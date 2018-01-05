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

== Installation ==

Download and place in a folder called StubUserWikiAuth

Add this to LocalSettings.php:

```lang=php
wfLoadExtension( 'StubUserWikiAuth' );
```

Then configure it at your will. The extension doesn't set up an authentication
provider automatically, you should configure it yourself. In theory, it allows
you to even provide more than one remote authentication provider.

```
$wgAuthManagerAutoConfig['primaryauth'][StubUserWikiAuth\StubUserWikiPasswordAuthenticationProvider::class] = [
	'class' => StubUserWikiAuth\StubUserWikiPasswordAuthenticationProvider::class,
	'args' => [ [
		// URL to the remote api.php endpoint
		'apiUrl' => 'https://www.mediawiki.org/w/api.php',',
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

