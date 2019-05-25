<?php
/**
 * Populates the user table with information from other tables.
 * Useful to fill the user table with stub data after importing
 * content from other grabbers.
 *
 * @file
 * @ingroup Maintenance
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.0.1
 * @date 2 April 2019
 */

/**
 * Set the correct include path for PHP
 */
ini_set( 'include_path', __DIR__ . '/../../../maintenance' );

require_once 'Maintenance.php';

class PopulateUserTable extends Maintenance {

	/**
	 * List of tables to populate users from
	 *
	 * @var array
	 */
	protected $tables;

	/**
	 * Array of valid tables and columns that contain user id and name
	 *
	 * @var array
	 */
	protected $validTables = [
			'revision' => [
				'id' => 'rev_user',
				'name' => 'rev_user_text'
			],
			'logging' => [
				'id' => 'log_user',
				'name' => 'log_user_text'
			],
			'image' => [
				'id' => 'img_user',
				'name' => 'img_user_text'
			],
			'oldimage' => [
				'id' => 'oi_user',
				'name' => 'oi_user_text'
			],
			'filearchive' => [
				'id' => 'fa_user',
				'name' => 'fa_user_text'
			],
			'archive' => [
				'id' => 'ar_user',
				'name' => 'ar_user_text'
			],
			'ipblocks' => [
				'id' => 'ipb_by',
				'name' => 'ipb_by_text'
			]
		];

	/**
	 * Handle to the database connection
	 *
	 * @var DatabaseBase
	 */
	protected $dbw;

	/**
	 * MediaWikiBot instance
	 *
	 * @var MediaWikiBot
	 */
	protected $bot;

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Populates the user table creating stub users (user ID and name) from other tables.';
		$this->addOption( 'db', 'Database name, if we don\'t want to write to $wgDBname', false /* required? */, true /* withArg */ );
		$this->addOption( 'tables', 'Tables to grab users from (pipe separated list) revision, logging, image, oldimage, filearchive, archive, ipblocks', false, true );
	}

	public function execute() {
		global $wgDBname;

		# Get a single DB_MASTER connection
		$this->dbw = wfGetDB( DB_MASTER, [], $this->getOption( 'db', $wgDBname ) );

		$tables = $this->getOption( 'tables' );
		if ( !is_null( $tables ) ) {
			$this->tables = explode( '|', $tables );

			# Check that no invalid tables were provided
			$invalidTables = array_diff( $this->tables, array_keys( $this->validTables ) );
			if ( count( $invalidTables ) > 0 ) {
				$this->error( sprintf( 'Invalid tables provided: %s',
					implode( ',', $invalidTables ) ), 1 );
			}
		} else {
			$this->tables = array_keys( $this->validTables );
		}
		
		foreach( $this->tables as $table ) {
			$this->populateUsersFromTable( $table );
		}
	}

	function populateUsersFromTable( $table ) {
		$columnInfo = $this->validTables[$table];
		$userIDField = $columnInfo['id'];
		$userNameField = $columnInfo['name'];

		# Dummy values for all required fields
		$e = [
			'user_id' => '',
			'user_name' => '',
			'user_real_name' => '',
			'user_password' => '',
			'user_newpassword' => '',
			'user_email' => '',
			'user_touched' => wfTimestampNow(),
			'user_token' => ''
		];

		$this->output( "Populating users from table $table..." );

		$count = 0;
		$lastUserId = 0;

		while ( true ) {
			$result = $this->dbw->select(
				[ $table, 'user' ],
				[ $userIDField, $userNameField ],
				[
					'user_id' => null,
					"$userIDField > $lastUserId"
				],
				__FUNCTION__,
				[
					'DISTINCT',
					'ORDER BY' => $userIDField,
					'LIMIT' => $this->mBatchSize,
				],
				[
					'user' => [ 'LEFT JOIN', "user_id=$userIDField" ]
				]
			);

			if ( !$result->numRows() ) {
				break;
			}

			$row = $result->fetchRow();
			while ( $row ) {
				$lastUserId = $row[$userIDField];
				$e['user_id'] = $lastUserId;
				$e['user_name'] = $row[$userNameField];
				$inserted = $this->dbw->insert(
					'user',
					$e,
					__METHOD__,
					# If there have been a rename in the middle, can be
					# duplicate ID for different user names
					[ 'IGNORE' ]
				);
				if ( $inserted ) {
					$count++;
					if ( $count % 500 == 0 ) {
						$this->output( "$count insertions...\n" );
					}
				}
				$row = $result->fetchRow();
			}
		}
		$this->output( "Done: $count insertions.\n" );
	}
}

$maintClass = 'PopulateUserTable';
require_once RUN_MAINTENANCE_IF_MAIN;
