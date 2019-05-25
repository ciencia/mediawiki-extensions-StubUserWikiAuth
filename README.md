# mediawiki-extensions-StubUserWikiAuth
MediaWiki extension for authenticating an account against a remote wiki, when
the user row is a stub

It's only used for users that have an empty password field on the database.

The purpose is to allow users to log in after importing a wiki dump from
another site, without having database access, providing they're the legitimate
owners of that account.

After importing the dump, you should run the maintenance script located in the
maintenance directory, to populate the user table with user names fetched from
the page histories and logs.

This extension was based on an early version of [MediaWikiAuth](https://www.mediawiki.org/wiki/Extension:MediaWikiAuth)

## Installation

Download and place in a folder called StubUserWikiAuth

Add this to LocalSettings.php:

```php
wfLoadExtension( 'StubUserWikiAuth' );
```

Then configure it at your will. The extension doesn't set up an authentication
provider automatically, you should configure it yourself. In theory, it allows
you to even provide more than one remote authentication provider.

```php
$wgAuthManagerAutoConfig['primaryauth'][StubUserWikiAuth\StubUserWikiPasswordAuthenticationProvider::class] = [
	'class' => StubUserWikiAuth\StubUserWikiPasswordAuthenticationProvider::class,
	'args' => [ [
		// URL to the remote api.php endpoint
		'apiUrl' => 'https://www.mediawiki.org/w/api.php',
		// URL to the Special:Preferences page (may be needed in some setups)
		'prefsUrl' => 'https://www.mediawiki.org/wiki/Special:Preferences',
		// Make this authentication not authoritative
		'authoritative' => false,
		// Prompt the user to change their password on first successful login
		// The user can skip it, however. (default: true)
		'promptPasswordChange' => true
		// Fetch user preferences from the remote wiki. (default:false)
		// You can set it to an array of preferences that *won't* be imported
		'fetchUserOptions' => true
	] ],
	// Weight of this authentication provider against others
	// 10 should be fine
	'sort' => 10,
];
```

## Logging

You can set up a log for diagnostic purposes, to see what external requests
have been made. The logs don't contain private information like passwords,
only the user name and if the login and import was successful, or if not what
was the response from the remote api.

Example:

```php
$wgDebugLogGroups['StubUserWikiAuth'] => '/var/log/mediawiki/StubUserWikiAuth_' . date('Ymd') . '.log';
```

## Upgrading

Since May 2019, a bug was fixed on the maintenance script that was inserting
rows on the `user` table with `user_timestamp` set to `'0'`. This can cause
problems on recent versions of MediaWiki. If you ran the maintenance script
before that date, you probably want to manually update the `user` table for
those rows created with a bad timestamp, for example:

```sql
update user set user_touched = '20170729092529' where user_touched < '1';
```

The `user` table may have a different name depending if you have configured a
table prefix.

## Features not supported

 - It doesn't write any on-wiki log to see what users were successfully
   logged-in and imported. You can, however, set up a log as described above.
 - Also, there's no public flag or indication about a user being imported.
   Nobody can know (unless looking at the database or server logs) if a user
   was imported unless the user make edits on their account.
 - It doesn't import the watchlist. Large watchlists may be problematic, and
   it's easy for an user to edit his/her watchlist in raw on both wikis to
   copy & paste it on the new wiki.
 
