<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Auth
 */

namespace StubUserWikiAuth;

use User;
use Status;
use StatusValue;
use MWCryptRand;
use Exception;
use DBAccessObjectUtils;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use Mediawiki\MediaWikiServices;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * A primary authentication provider for stub users that authenticates against
 * a remote wiki, populating the password and other fields so the local user
 * is usable again.
 * @ingroup Auth
 * @since 1.27
 */
class StubUserWikiPasswordAuthenticationProvider
	extends \MediaWiki\Auth\AbstractPasswordPrimaryAuthenticationProvider
{

	protected $apiUrl = false;
	protected $prefsUrl = false;
	protected $timeout = 10;
	protected $fetchUserOptions = false;
	protected $promptPasswordChange = true;
	private $cookieJar;

	/**
	 * @param array $params Settings
	 *  - apiUrl: (required) URL of the remote wiki api.php endpoint to perform
	 *            the authentication
	 *  - prefsUrl: (optional) URL of the remote wiki Special:Preferences page
	 *              to try to screenscrap user email if not provided by the api
	 *  - timeout: (optional) Timeout (in seconds) for connections to the remote
	 *             wiki. By default 10 seconds.
	 *  - fetchWatchlist: (optional) Bool to indicate if you want to populate
	 *             the watchlist from the remote one. Note that large watchlists
	 *             can take a long time to fetch and insert on our database!
	 *             Disabled by default
	 *  - fetchUserOptions: (optional) Bool|array to indicate if you want to
	 *             import user preferences from the remote wiki into this one
	 *             If an array, will import all preferences except the ones from
	 *             the array.
	 *             Disabled by default
	 *  - promptPasswordChange: (optional) Bool to display a password change
	 *             dialog so the user can set a new password after login
	 *             Enabled by default
	 */
	public function __construct( $params = [] ) {
		parent::__construct( $params );
		$this->apiUrl = $params['apiUrl'];
		if ( !$this->apiUrl ) {
			throw new Exception( 'apiUrl param must be provided' );
		}
		$this->prefsUrl = $params['prefsUrl'];
		if ( isset( $params['timeout'] ) ) {
			$this->timeout = $params['timeout'];
		}
		if ( isset( $params['fetchUserOptions'] ) ) {
			$this->fetchUserOptions = $params['fetchUserOptions'];
		}
		if ( isset( $params['promptPasswordChange'] ) && !$params['promptPasswordChange'] ) {
			$this->promptPasswordChange = false;
		}
	}

	public function beginPrimaryAuthentication( array $reqs ) {
		$req = AuthenticationRequest::getRequestByClass( $reqs, PasswordAuthenticationRequest::class );
		if ( !$req ) {
			return AuthenticationResponse::newAbstain();
		}

		# If no username nor password is provided, abstain. May be handled by
		# another provider, or just fallback to the built-in error messages
		if ( $req->username === null || $req->password === null || $req->username == '' || $req->password == '' ) {
			return AuthenticationResponse::newAbstain();
		}


		$username = $this->getCanonicalUsername( $req->username );
		if ( $username === false ) {
			return AuthenticationResponse::newAbstain();
		}

		# Check if the user exists
		$fields = [
			'user_id', 'user_password', 'user_password_expires',
		];

		$loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbr = $loadBalancer->getConnection( DB_REPLICA );
		$row = $dbr->selectRow(
			'user',
			$fields,
			[ 'user_name' => $username ],
			__METHOD__
		);
		# Only try to authenticate stub users with no password set
		if ( !$row || $row->user_password != '' ) {
			return AuthenticationResponse::newAbstain();
		}

		$mwAuth = new MWAuth( [
			'apiUrl' => $this->apiUrl,
			'prefsUrl' => $this->prefsUrl,
			'timeout' => $this->timeout
		] );

		$loginStatus = $mwAuth->remoteLogin( $username, $req->password );

		if ( !$loginStatus->isGood() ) {
			return AuthenticationResponse::newFail( $loginStatus->getErrors()[0]['message'] );
		}

		# Populate our password
		$newHash = $this->getPasswordFactory()->newFromPlaintext( $req->password );

		$dbw = $loadBalancer->getConnection( DB_PRIMARY );
		$dbw->update(
			'user',
			[ 'user_password' => $newHash->toString() ],
			[ 'user_id' => $row->user_id ],
			__METHOD__
		);

		$user = User::newFromName( $username );
		$user->setToken();
		
		$email = null;
		$realName = null;
		$emailAuthenticated = null;

		$prefs = $mwAuth->fetchPreferences( $realName, $email, $emailAuthenticated, !!$this->fetchUserOptions );

		if ( $realName ) {
			$user->setRealName( $realName );
		}
		if ( $email ) {
			$user->setEmail( $email );
			if ( $emailAuthenticated ) {
				$user->setEmailAuthenticationTimestamp( $emailAuthenticated );
			} else {
				wfDebugLog( 'StubUserWikiAuth', 'Send confirmation mail' );
				$user->sendConfirmationMail();
			}
		}
		if ( $this->fetchUserOptions && $prefs ) {
			if ( is_array( $this->fetchUserOptions ) ) {
				$prefs = array_diff( $prefs, $this->fetchUserOptions );
			}
			$userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
			foreach ( $prefs as $name => $val ) {
				$userOptionsManager->setOption( $user, $name, $val );
			}
			$userOptionsManager->saveOptions( $user );
		}
		
		$user->saveSettings();

		if ( $this->promptPasswordChange ) {
			//$this->setPasswordResetFlag( $username, Status::newGood() );
			$reset = (object)[
				'msg' => wfMessage( 'stubuserwikiauth-resetpass' ),
				'hard' => false,
			];
			$this->manager->setAuthenticationSessionData( 'reset-pass', $reset );
		}

		# We abstain here to let the primary password authentication provider
		# handle all the login stuff, since the user has been set up correctly
		return AuthenticationResponse::newAbstain();
	}

	public function testUserCanAuthenticate( $username ) {
		$username = $this->getCanonicalUsername($username);

		if ( $username === false ) {
			return false;
		}

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$row = $dbr->selectRow(
			'user',
			[ 'user_password' ],
			[ 'user_name' => $username ],
			__METHOD__
		);
		if ( !$row ) {
			return false;
		}
		
		return ( $row->user_password === '' );
	}

	public function testUserExists( $username, $flags = User::READ_NORMAL ) {
		$username = $this->getCanonicalUsername($username);
		if ( $username === false ) {
			return false;
		}

		list( $db, $options ) = DBAccessObjectUtils::getDBOptions( $flags );
		return (bool)MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( $db )->selectField(
			[ 'user' ],
			'user_id',
			[ 'user_name' => $username ],
			__METHOD__,
			$options
		);
	}

	public function providerAllowsAuthenticationDataChange(
		AuthenticationRequest $req, $checkData = true
	) {
		return StatusValue::newGood( 'ignored' );
	}

	public function providerChangeAuthenticationData( AuthenticationRequest $req ) {
		return;
	}

	public function accountCreationType() {
		return self::TYPE_CREATE;
	}

	public function testForAccountCreation( $user, $creator, array $reqs ) {
		return StatusValue::newGood();
	}

	public function beginPrimaryAccountCreation( $user, $creator, array $reqs ) {
		return AuthenticationResponse::newAbstain();
	}

	public function finishAccountCreation( $user, $creator, AuthenticationResponse $res ) {
		return null;
	}

	private function getCanonicalUserName( $username ) {
		$services = MediaWikiServices::getInstance();
		$userNameUtils = $services->getUsernameUtils();

		return $userNameUtils->getCanonical( $username, 'usable' );
	}
}


