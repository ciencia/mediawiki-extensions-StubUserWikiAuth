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
 */

namespace StubUserWikiAuth;
use MWHttpRequest;
use StatusValue;
use FormatJson;
use Exception;

 /**
 * Logic for logging in to remote wiki and fetching various data from the user
 */
class MWAuth {

	private $apiUrl = false;
	private $prefsUrl = false;
	private $timeout = 10;
	private $username = null;
	private $cookieJar;

	/**
	 * @param array $params Settings
	 *  - apiUrl: (required) URL of the remote wiki api.php endpoint to perform
	 *            the authentication
	 *  - prefsUrl: (optional) URL of the remote wiki Special:Preferences page
	 *              to try to screenscrap user email if not provided by the api
	 *  - timeout: (optional) Timeout (in seconds) for connections to the remote
	 *             wiki. By default 10 seconds.
	 */
	public function __construct( $params = [] ) {
		$this->apiUrl = $params['apiUrl'];
		if ( !$this->apiUrl ) {
			throw new Exception( 'apiUrl param must be provided' );
		}
		$this->prefsUrl = $params['prefsUrl'];
		if ( isset( $params['timeout'] ) ) {
			$this->timeout = $params['timeout'];
		}
	}

	/**
	 * Performs a remote login to a wiki with the given username and password.
	 * @param string $username User name
	 * @param string $password Password
	 * @returns StatusValue Status of the login
	 */
	public function remoteLogin( $username, $password ) {
		# The user should exist remotely. Let's try to login.
		$remoteReqOptions = [
			'method' => 'POST',
			'userAgent' => 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)',
			'timeout' => $this->timeout
		];
		$login_vars = [
			'action' => 'login',
			'lgname' => $username,
			'lgpassword' => $password,
			'format' => 'json'
		];
		$requestCount = 0;
		$remoteReq = MWHttpRequest::factory( $this->apiUrl, $remoteReqOptions, __METHOD__ );
		do {

			# Prevent infinite loops
			if ( $requestCount > 3 ) {
				wfDebugLog( 'StubUserWikiAuth', "Too many requests logging in for {$username}: " );
				return StatusValue::newFatal( wfMessage( 'unknown-error' ) );
			}

			# BEGIN HACK: MWHttpRequest is broken on 1.34. It doesn't send the
			# cookies to the final request if set with the CookieJar
			# Send them manually as a standalone header
			$plainCookies = $this->GetCookies();
			# Clear cookies as we send them manually
			$remoteReq->setCookieJar( new \CookieJar() );
			if ( $plainCookies ) {
				$remoteReq->setHeader( 'Cookie', $plainCookies );
			}
			# END Hack
			$remoteReq->setData( $login_vars );
			$remoteStatus = $remoteReq->execute();
			$requestCount++;

			if ( !$remoteStatus->isOK() || $remoteReq->getStatus() != 200 ) {
				wfDebugLog( 'StubUserWikiAuth', "Failed request for {$username}: " .
					"Status: " . $remoteReq->getStatus() .
					". Errors: " . print_r( $remoteStatus->getErrors(), true ) .
					". Content: " . $remoteReq->getContent() );
				return StatusValue::newFatal( wfMessage( 'unknown-error' ) );
			}
			$this->cookieJar = $remoteReq->getCookieJar();
			$results = FormatJson::decode( $remoteReq->getContent(), true );

			# Did we get in? Look for result: 'Success'
			if ( isset( $results['login'] ) ) {
				$login = $results['login'];
				if ( $login['result'] != 'Success' && $login['result'] != 'NeedToken' ) {
					wfDebugLog( 'StubUserWikiAuth', "Login result for {$username}: " . print_r( $results, true ) );
				}
				switch ( $login['result'] ) {
					case 'Success':
						wfDebugLog( 'StubUserWikiAuth', "SuccessLogin for {$username}" );
						$this->username = $username;
						return StatusValue::newGood();
						break;

					case 'NotExists':
						return StatusValue::newFatal(
							wfMessage( 'nosuchuser', htmlspecialchars( $username ) )
						);
						break;

					case 'NeedToken':
						# Set cookies and break out to resubmit
						$remoteReq->setCookieJar( $this->cookieJar );
						$login_vars['lgtoken'] = $login['token'];
						break;

					case 'WrongToken':
						return StatusValue::newFatal(
							wfMessage( 'internalerror' )
						);
						break;

					case 'EmptyPass':
						return StatusValue::newFatal(
							wfMessage( 'wrongpasswordempty' )
						);
						break;

					case 'WrongPass':
					case 'WrongPluginPass':
						return StatusValue::newFatal(
							wfMessage( 'wrongpassword' )
						);
						break;

					case 'Throttled':
						global $wgLang, $wgPasswordAttemptThrottle;
						return StatusValue::newFatal(
							wfMessage( 'login-throttled', $wgLang->formatDuration( $wgPasswordAttemptThrottle['seconds'] ) )
						);
						break;

					default:
						return StatusValue::newFatal(
							wfMessage( 'unknown-error' )
						);
						break;
				}
			}
		} while ( isset( $results['login'] ) && $login['result'] == 'NeedToken' );

		wfDebugLog( 'StubUserWikiAuth', "Failed request for {$username}: No login object found. Is the remote wiki api URL correct?" );

		return StatusValue::newFatal(
			wfMessage( 'unknown-error' )
		);
	}

	public function logout() {
		# Logout once we're finished
		$remoteReqOptions = [
			'method' => 'POST',
			'userAgent' => 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)',
			'timeout' => $this->timeout
		];
		$logout_vars = [ 'action' => 'logout', 'format' => 'json' ];
		$remoteReq = MWHttpRequest::factory( $this->apiUrl, $remoteReqOptions, __METHOD__ );
		$remoteReq->setCookieJar( $this->cookieJar );
		$remoteReq->setData( $logout_vars );
		$remoteReq->execute();
	}

	/**
	 * Fetches user information from the remote wiki
	 * @param string $realName Fetched real name from preferences
	 * @param string $email Fetched Email
	 * @param string $emailAuthenticated Timestamp of email authentication
	 * @param bool $fetchOptions Wether to fetch preferences or not
	 * @returns array User preferences, or false if failed
	 */
	public function fetchPreferences( &$realName, &$email, &$emailAuthenticated, $fetchOptions ) {
		# Get user preferences
		$prefs_vars = [
			'action' => 'query',
			'meta' => 'userinfo',
			'uiprop' => 'email|realname',
			'format' => 'json'
		];
		if ( $fetchOptions ) {
			$prefs_vars['uiprop'] .= '|options';
		}

		$remoteReqOptions = [
			'method' => 'POST',
			'userAgent' => 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)',
			'timeout' => $this->timeout
		];
		$remoteReq = MWHttpRequest::factory( $this->apiUrl, $remoteReqOptions, __METHOD__ );
		#$remoteReq->setCookieJar( $this->cookieJar );
		# BEGIN HACK: MWHttpRequest is broken on 1.34. It doesn't send the
		# cookies to the final request if set with the CookieJar
		# Send them manually as a standalone header
		$remoteReq->setHeader( 'Cookie', $this->GetCookies() );
		# END Hack
		$remoteReq->setData( $prefs_vars );
		$remoteStatus = $remoteReq->execute();

		if ( !$remoteStatus->isOK() ) {
			wfDebugLog( 'StubUserWikiAuth', "Failed request to get user preferences for {$this->username}: Errors: " .
				print_r( $remoteStatus->getErrors(), true ) . 
				". Content: " . $remoteReq->getContent() );
			return false;
		}
		$results = FormatJson::decode( $remoteReq->getContent(), true );

		if ( isset( $results['query'] ) && isset( $results['query']['userinfo'] ) ) {
			// Older wikis might not expose this in the API (1.15+)
			if ( isset( $results['query']['userinfo']['email'] ) ) {
				$email = $results['query']['userinfo']['email'];
				if ( isset( $results['query']['userinfo']['emailauthenticated'] ) ) {
					$emailAuthenticated = wfTimestamp( TS_MW, $results['query']['userinfo']['emailauthenticated'] );
				}
			}
			// This is 1.18+
			if ( isset( $results['query']['userinfo']['realname'] ) ) {
				$realName = $results['query']['userinfo']['realname'];
			}
		}

		if ( !$email ) {
			$this->screenScrapAdditionalFields( $realName, $email );
		}
		
		if ( $fetchOptions && isset( $results['query'] ) && isset( $results['query']['userinfo'] ) ) {
			return $results['query']['userinfo']['options'];
		}
		return false;
	}

	private function screenScrapAdditionalFields( &$realName, &$email ) {
		$remoteReqOptions = [
			'method' => 'GET',
			'userAgent' => 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)',
			'timeout' => $this->timeout
		];
		$remoteReq = MWHttpRequest::factory( wfAppendQuery( $this->prefsUrl, 'uselang=qqx' ),
			$remoteReqOptions, __METHOD__ );
		$remoteReq->setCookieJar( $this->cookieJar );
		$remoteStatus = $remoteReq->execute();
		$results = $remoteReq->getContent();
		
		if ( !$remoteStatus->isOK() ) {
			wfDebugLog( 'StubUserWikiAuth', sprintf(
				"Failed request for screenscraping email. Errors: \n%s\nContent:\n%s",
				print_r( $remoteStatus->getErrors(), true ), $results ) );
			return null;
		}

		# wpRealName = 1.15 and older, wprealname = 1.16+
		if ( !$realName && preg_match( '^.*wp(R|r)eal(N|n)ame.*value="(.*?)".*^', $results, $matches ) ) {
			$realName = stripslashes( html_entity_decode( $matches[3], ENT_QUOTES, 'UTF-8' ) );
		}
		# wpUserEmail = 1.15 and older, wpemailaddress = 1.16+
		if ( preg_match( '^.*(wpUserEmail|wpemailaddress).*value="(.*?)".*^', $results, $matches ) ) {
			$email = stripslashes( html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ) );
		}
	}

	private function GetCookies() {
		# HACK: MWHttpRequest is broken on 1.34. It doesn't send the
		# cookies to the final request if set with the CookieJar
		# Send them manually as a standalone header
		$parsedUrl = wfParseUrl( $this->apiUrl );
		$path = $parsedUrl['path'];
		$host = $parsedUrl['host'];
		if ( $this->cookieJar !== null ) {
			return $this->cookieJar->serializeToHttpRequest( $path, $host );
		}
		return null;
	}
}
