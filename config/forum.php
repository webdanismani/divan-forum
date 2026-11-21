<?php

/*
|--------------------------------------------------------------------------
| Forum core configuration
|--------------------------------------------------------------------------
| Product identity, the permission catalogue and the schema of the options
| that are editable from the admin control panel. Labels are translation
| keys (see lang/tr.json).
*/

return [

    'product' => [
        // Working product name - change it here, it is used everywhere.
        'name' => 'Divan',
        'version' => '0.3.4',
        // Shown in the copyright line at the very bottom of every page.
        'vendor' => 'Web Danışmanı',
        'vendor_url' => 'https://webdanismani.com',
        'copyright_from' => 2024,
    ],

    /*
    | Permission catalogue. "node" => true means the permission can be
    | overridden per forum (node). Each value is: allow | never | (not set).
    */
    'permissions' => [
        'General' => [
            'node.view' => ['label' => 'View forums and threads', 'node' => true],
            'profile.view' => ['label' => 'View member profiles', 'node' => false],
            'search.use' => ['label' => 'Use search', 'node' => false],
            'conversation.start' => ['label' => 'Start private conversations', 'node' => false],
            'profile.post' => ['label' => 'Write on member profiles', 'node' => false],
            'api.use' => ['label' => 'Create API keys and use the API', 'node' => false],
            'shoutbox.post' => ['label' => 'Write in the shoutbox', 'node' => false],
            'replies.saved' => ['label' => 'Keep saved replies for the editor', 'node' => false],
        ],
        'Profile and privacy' => [
            'friends.use' => ['label' => 'Add friends and receive friend requests', 'node' => false],
            'privacy.profile' => ['label' => 'Choose who can see their profile', 'node' => false],
            'privacy.contact' => ['label' => 'Choose who can message them and write on their profile', 'node' => false],
            'privacy.online' => ['label' => 'Hide their online status', 'node' => false],
            'account.rename' => ['label' => 'Change their own username', 'node' => false],
            'account.delete' => ['label' => 'Ask for their account to be deleted', 'node' => false],
            'profile.links' => ['label' => 'Write site addresses in the profile text (about, location)', 'node' => false],
            'signature.use' => ['label' => 'Use a signature', 'node' => false],
            'signature.links' => ['label' => 'Put links in the signature', 'node' => false],
            'signature.images' => ['label' => 'Put images in the signature', 'node' => false],
            'signature.long' => ['label' => 'Use the longer signature limit', 'node' => false],
        ],
        'Posting' => [
            'thread.create' => ['label' => 'Create threads', 'node' => true],
            'post.create' => ['label' => 'Reply to threads', 'node' => true],
            'post.editOwn' => ['label' => 'Edit own posts', 'node' => true],
            'post.deleteOwn' => ['label' => 'Delete own posts', 'node' => true],
            'post.react' => ['label' => 'React to posts', 'node' => true],
            'post.report' => ['label' => 'Report posts', 'node' => true],
            'attachment.view' => ['label' => 'View attachments', 'node' => true],
            'attachment.upload' => ['label' => 'Upload attachments', 'node' => true],
            'poll.create' => ['label' => 'Add polls to threads', 'node' => true],
            'poll.vote' => ['label' => 'Vote in polls', 'node' => true],
        ],
        'Moderation' => [
            'mod.editAny' => ['label' => 'Edit any post', 'node' => true],
            'mod.deleteAny' => ['label' => 'Delete any post or thread', 'node' => true],
            'mod.hardDelete' => ['label' => 'Permanently delete content', 'node' => true],
            'mod.viewDeleted' => ['label' => 'View deleted content', 'node' => true],
            'mod.approve' => ['label' => 'Approve moderated content', 'node' => true],
            'mod.lock' => ['label' => 'Lock and unlock threads', 'node' => true],
            'mod.stick' => ['label' => 'Stick and unstick threads', 'node' => true],
            'mod.move' => ['label' => 'Move threads', 'node' => true],
            'mod.merge' => ['label' => 'Merge, split and copy threads', 'node' => true],
            'mod.manageReports' => ['label' => 'Manage reported content', 'node' => false],
            'mod.warn' => ['label' => 'Warn members', 'node' => false],
            'mod.spamClean' => ['label' => 'Use the spam cleaner (ban and remove all content)', 'node' => false],
        ],
        'Administration' => [
            'admin.access' => ['label' => 'Access the admin control panel', 'node' => false],
        ],
    ],

    /*
    | Options editable in the admin panel. type: string|text|int|bool|select|secret
    | ("secret" is a password field: left empty, the stored value is kept).
    */
    'settings' => [
        'Board' => [
            'board.name' => ['label' => 'Board name', 'type' => 'string', 'default' => 'Divan'],
            'board.description' => ['label' => 'Board description', 'type' => 'text', 'default' => 'Topluluğumuza hoş geldiniz.'],
            'board.footer_text' => ['label' => 'Footer text', 'type' => 'string', 'default' => ''],
            'board.locale' => ['label' => 'Default language', 'type' => 'select', 'default' => 'tr', 'options' => ['tr' => 'Türkçe', 'en' => 'English']],
            'board.default_theme' => ['label' => 'Default theme', 'type' => 'select', 'default' => 'auto', 'options' => ['auto' => 'Automatic', 'light' => 'Light', 'dark' => 'Dark']],
        ],
        'Welcome area for guests' => [
            'welcome.enabled' => ['label' => 'Show a welcome area at the top of the home page to visitors who are not signed in', 'type' => 'bool', 'default' => true],
            'welcome.title' => ['label' => 'Welcome title (empty = "Welcome to" and the board name)', 'type' => 'string', 'default' => ''],
            'welcome.text' => ['label' => 'Welcome text (empty = the board description)', 'type' => 'text', 'default' => ''],
        ],
        'Listing' => [
            'board.threads_per_page' => ['label' => 'Threads per page', 'type' => 'int', 'default' => 20, 'min' => 5, 'max' => 100],
            'board.posts_per_page' => ['label' => 'Posts per page', 'type' => 'int', 'default' => 15, 'min' => 5, 'max' => 100],
            'board.thread_preview' => ['label' => 'Show the start of a thread when the pointer rests on its title', 'type' => 'bool', 'default' => true],
        ],
        'Registration and posting' => [
            'registration.enabled' => ['label' => 'Allow new registrations', 'type' => 'bool', 'default' => true],
            'registration.verify_email' => ['label' => 'New members must confirm their e-mail address before they can post', 'type' => 'bool', 'default' => false],
            'posting.flood_seconds' => ['label' => 'Seconds a member must wait between posts', 'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 3600],
            'posting.edit_minutes' => ['label' => 'Minutes a member may edit their own post (0 = unlimited)', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 525600],
            'posting.max_tags' => ['label' => 'Tags per thread (0 = tags off)', 'type' => 'int', 'default' => 5, 'min' => 0, 'max' => 20],
            'reports.reasons' => ['label' => 'Reasons offered when a post is reported (one per line)', 'type' => 'text', 'default' => "Spam veya reklam\nHakaret, küfür veya taciz\nYanlış forumda açılmış\nTelif hakkı ihlali\nDolandırıcılık şüphesi"],
            'account.rename_days' => ['label' => 'Days a member waits between two username changes (0 = no waiting)', 'type' => 'int', 'default' => 30, 'min' => 0, 'max' => 3650],
            'posting.title_case' => ['label' => 'Start every word of a thread title with a capital letter', 'type' => 'bool', 'default' => (bool) env('FORUM_TITLE_CASE', true)],
            'profile.allowed_domains' => ['label' => 'Site addresses every member may write in the profile text (one per line; the forum\'s own address always may)', 'type' => 'text', 'default' => ''],
            'replies.max' => ['label' => 'Saved replies a member can keep', 'type' => 'int', 'default' => 20, 'min' => 1, 'max' => 200],
            'posting.media_embeds' => ['label' => 'Turn video and social media links into embedded players', 'type' => 'bool', 'default' => true],
        ],
        'Private conversations' => [
            'conversations.quota' => ['label' => 'Conversations a member can keep in the inbox (0 = no limit; a user group can have its own number)', 'type' => 'int', 'default' => 100, 'min' => 0, 'max' => 100000],
        ],
        'Signatures' => [
            'signature.max_length' => ['label' => 'Longest signature (characters)', 'type' => 'int', 'default' => 200, 'min' => 20, 'max' => 5000],
            'signature.long_length' => ['label' => 'Longest signature for groups with the longer limit', 'type' => 'int', 'default' => 500, 'min' => 20, 'max' => 5000],
        ],
        'Attachments' => [
            'attachments.extensions' => ['label' => 'Allowed file extensions (comma separated)', 'type' => 'string', 'default' => 'jpg, jpeg, png, gif, webp, pdf, zip, rar, 7z, txt, doc, docx, xls, xlsx'],
            'attachments.max_kb' => ['label' => 'Largest file size (KB)', 'type' => 'int', 'default' => 4096, 'min' => 16, 'max' => 204800],
            'attachments.max_per_post' => ['label' => 'Files per message', 'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 50],
        ],
        'E-mail' => [
            'mail.driver' => ['label' => 'Outgoing mail', 'type' => 'select', 'default' => 'log', 'options' => ['log' => 'Off (messages are only written to the log)', 'smtp' => 'SMTP server']],
            'mail.host' => ['label' => 'SMTP host', 'type' => 'string', 'default' => ''],
            'mail.port' => ['label' => 'SMTP port', 'type' => 'int', 'default' => 587, 'min' => 1, 'max' => 65535],
            'mail.encryption' => ['label' => 'SMTP encryption', 'type' => 'select', 'default' => 'tls', 'options' => ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None']],
            'mail.username' => ['label' => 'SMTP user name', 'type' => 'string', 'default' => ''],
            'mail.password' => ['label' => 'SMTP password', 'type' => 'secret', 'default' => ''],
            'mail.from_address' => ['label' => 'Sender address', 'type' => 'string', 'default' => ''],
            'mail.from_name' => ['label' => 'Sender name (empty = board name)', 'type' => 'string', 'default' => ''],
        ],
        'Spam protection' => [
            'captcha.provider' => ['label' => 'Captcha on the registration form', 'type' => 'select', 'default' => 'none', 'options' => ['none' => 'Off', 'turnstile' => 'Cloudflare Turnstile', 'recaptcha' => 'Google reCAPTCHA v2']],
            'captcha.site_key' => ['label' => 'Captcha site key', 'type' => 'string', 'default' => ''],
            'captcha.secret_key' => ['label' => 'Captcha secret key', 'type' => 'secret', 'default' => ''],
            'spam.stopforumspam' => ['label' => 'Check new registrations against StopForumSpam', 'type' => 'bool', 'default' => false],
            'spam.link_min_posts' => ['label' => 'Posts with links wait for approval until the member has this many posts (0 = off)', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 1000],
            'censor.words' => ['label' => 'Censored words, one per line ("word=replacement" and "wor*" are allowed)', 'type' => 'text', 'default' => ''],
            'censor.mask' => ['label' => 'Text shown instead of a censored word', 'type' => 'string', 'default' => '***'],
        ],
        'Sign in with other accounts' => [
            'oauth.google_client_id' => ['label' => 'Google client ID', 'type' => 'string', 'default' => ''],
            'oauth.google_client_secret' => ['label' => 'Google client secret', 'type' => 'secret', 'default' => ''],
            'oauth.github_client_id' => ['label' => 'GitHub client ID', 'type' => 'string', 'default' => ''],
            'oauth.github_client_secret' => ['label' => 'GitHub client secret', 'type' => 'secret', 'default' => ''],
        ],
        'API and apps' => [
            'api.enabled' => ['label' => 'Enable the REST API (/api/v1)', 'type' => 'bool', 'default' => false],
            'pwa.enabled' => ['label' => 'Let visitors install the forum as an app (web app manifest and offline page)', 'type' => 'bool', 'default' => true],
            'push.enabled' => ['label' => 'Browser notifications for alerts (members switch them on in their account settings)', 'type' => 'bool', 'default' => true],
        ],
        'Wallet' => [
            'wallet.enabled' => ['label' => 'Enable member balances (top-up, paid thread promotion, membership packages)', 'type' => 'bool', 'default' => true],
            'wallet.min_topup' => ['label' => 'Smallest top-up amount', 'type' => 'int', 'default' => 50, 'min' => 1, 'max' => 100000],
            'wallet.max_topup' => ['label' => 'Largest top-up amount', 'type' => 'int', 'default' => 10000, 'min' => 1, 'max' => 1000000],
        ],
        'Advertising' => [
            'ads.enabled' => ['label' => 'Enable the advertising module', 'type' => 'bool', 'default' => true],
            'ads.placeholders' => ['label' => 'Show an "advertise here" box in free ad spaces', 'type' => 'bool', 'default' => true],
            'ads.auto_publish' => ['label' => 'Publish ads automatically once the card payment succeeds', 'type' => 'bool', 'default' => true],
            'ads.transfer_info' => ['label' => 'Bank transfer details shown to advertisers (leave empty to turn bank transfer off)', 'type' => 'text', 'default' => ''],
            'ads.paytr_merchant_id' => ['label' => 'PayTR merchant ID', 'type' => 'string', 'default' => ''],
            'ads.paytr_merchant_key' => ['label' => 'PayTR merchant key', 'type' => 'secret', 'default' => ''],
            'ads.paytr_merchant_salt' => ['label' => 'PayTR merchant salt', 'type' => 'secret', 'default' => ''],
            'ads.paytr_test_mode' => ['label' => 'PayTR test mode', 'type' => 'bool', 'default' => true],
            'ads.remind_days' => ['label' => 'Remind advertisers this many days before their ad ends (0 = off)', 'type' => 'int', 'default' => 3, 'min' => 0, 'max' => 30],
            'ads.receipt_seller' => ['label' => 'Seller details printed on receipts (company name, address, tax number)', 'type' => 'text', 'default' => ''],
        ],
        'Marketplace' => [
            'market.enabled' => ['label' => 'Enable the marketplace (forums marked as a marketplace hold listings)', 'type' => 'bool', 'default' => true],
            'market.require_phone' => ['label' => 'Only members with a verified phone number can post listings', 'type' => 'bool', 'default' => false],
            'market.feedback_min_posts' => ['label' => 'Posts a member needs before leaving trader feedback', 'type' => 'int', 'default' => 5, 'min' => 0, 'max' => 10000],
        ],
        'Phone verification' => [
            'sms.provider' => ['label' => 'Text message provider', 'type' => 'select', 'default' => 'none', 'options' => ['none' => 'Off', 'netgsm' => 'NetGSM', 'twilio' => 'Twilio', 'http' => 'Other provider (HTTP address)', 'log' => 'Test: write the code to the log file']],
            'sms.netgsm_username' => ['label' => 'NetGSM user name (subscriber number)', 'type' => 'string', 'default' => ''],
            'sms.netgsm_password' => ['label' => 'NetGSM API password', 'type' => 'secret', 'default' => ''],
            'sms.netgsm_sender' => ['label' => 'NetGSM sender name (message header)', 'type' => 'string', 'default' => ''],
            'sms.country_code' => ['label' => 'Country code added to local numbers', 'type' => 'string', 'default' => '90'],
            'sms.twilio_sid' => ['label' => 'Twilio account SID', 'type' => 'string', 'default' => ''],
            'sms.twilio_token' => ['label' => 'Twilio auth token', 'type' => 'secret', 'default' => ''],
            'sms.twilio_from' => ['label' => 'Twilio sender (number or name)', 'type' => 'string', 'default' => ''],
            'sms.http_url' => ['label' => 'Other provider: address that sends a message. {phone} (with country code), {local} (last 10 digits) and {message} are filled in', 'type' => 'secret', 'default' => ''],
            'sms.http_success' => ['label' => 'Other provider: text the answer contains when the message was accepted (empty = any successful answer)', 'type' => 'string', 'default' => ''],
        ],
        'AI helpers' => [
            'ai.api_key' => ['label' => 'Claude API key (from platform.claude.com); empty = AI helpers off', 'type' => 'secret', 'default' => ''],
            'ai.model' => ['label' => 'Model', 'type' => 'select', 'default' => 'claude-opus-5-5', 'options' => ['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5', 'claude-fable-5-2' => 'Claude Fable 5.2']],
            'ai.spam_check' => ['label' => 'Check the first posts of new members for spam (suspects go to the approval queue)', 'type' => 'bool', 'default' => true],
            'ai.spam_max_posts' => ['label' => 'Posts after which a member is no longer checked', 'type' => 'int', 'default' => 3, 'min' => 0, 'max' => 100],
            'ai.summaries' => ['label' => 'Offer an AI summary on long threads', 'type' => 'bool', 'default' => true],
            'ai.summary_min_posts' => ['label' => 'Posts a thread needs before a summary is offered', 'type' => 'int', 'default' => 10, 'min' => 3, 'max' => 1000],
        ],
        'Security and backups' => [
            'security.new_device_alerts' => ['label' => 'Tell members when their account is signed in to from a new device', 'type' => 'bool', 'default' => true],
            'backup.auto_days' => ['label' => 'Make a database backup automatically every this many days (0 = off)', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 30],
            'backup.keep' => ['label' => 'Automatic database backups to keep', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 60],
        ],
        'Shoutbox and live alerts' => [
            'shoutbox.enabled' => ['label' => 'Show the shoutbox on the home page', 'type' => 'bool', 'default' => true],
            'live.poll_seconds' => ['label' => 'Seconds between checks for new alerts while a page is open (0 = off)', 'type' => 'int', 'default' => 30, 'min' => 0, 'max' => 600],
        ],
    ],

    /*
    | Advertising manager. Prices are entered in this currency; the code is
    | what the payment provider expects, the symbol is what visitors see.
    */
    'ads' => [
        'currency' => 'TL',
        'currency_symbol' => '₺',
    ],

    /*
    | Edition / licensing. The free edition is fully functional; features
    | listed here are reserved for the premium package and are checked with
    | App\Forum\Edition::has(). Remote licence validation is not wired yet.
    */
    // Until licence keys are validated the edition is switched here: free | premium.
    'edition' => env('FORUM_EDITION', 'premium'),

    'premium_features' => [
        'attachments' => 'File attachments and media gallery',
        'advanced_search' => 'Full-text search engine integration',
        'custom_fields' => 'Custom user and thread fields',
        'paid_memberships' => 'Paid user group upgrades',
        'style_manager' => 'Visual style and template manager',
        'api' => 'REST API and webhooks',
    ],

    /*
    | The vendor's market for extra modules and themes.
    | Packages are only installed when their signature matches this public key.
    */
    'store' => [
        'url' => env('FORUM_STORE_URL', 'https://webdanismani.com/market'),
        // Ed25519 public key of the market (shown in its panel under Ayarlar).
        'public_key' => env('FORUM_STORE_KEY', 'KiQfzxl4SF8ecyRpqS1HgZMXYkg4gn5pxyg+kiZ/JAc='),
    ],

    /*
    | The vendor's licence server. A sold module or theme names its licence product in its manifest
    | ("license") and only runs while a licence code for that product is active on this forum
    | (App\Forum\License\Licenses). Every answer of the server is signed; the forum trusts it only when the
    | signature chain leads from the root key below to the server key below.
    */
    'license' => [
        'url' => env('FORUM_LICENSE_URL', 'https://lisans.webdanismani.com/api'),
        // Ed25519 public keys (hex): the vendor's offline root key and the key of the licence server.
        // They are part of the software, not of an installation: there is no environment variable for them.
        'root_key' => 'd7752208cdd08b029d703696c01dba9d2c00fa4d9db44cbf947be28802db8d95',
        'public_key' => '21bd777bf3ec5fadeb777c045f229a62fd80f10feeecd8bae635d72f8f105c8f',
        // Licence product of the forum software itself. The web installer does not go on without a code for it.
        'product' => 'wd-forum',
        // Where a code is ordered (free of charge for the forum itself); shown by the installer and on the licence page.
        'get_url' => env('FORUM_LICENSE_GET_URL', 'https://webdanismani.com'),
    ],

    // Where installed themes live (null = storage/app/themes).
    'themes_path' => null,

    // The board answers only under the address in APP_URL; a request for another host name is sent there.
    // Switch off only behind a proxy that replaces the Host header with an internal name.
    'force_host' => (bool) env('FORUM_FORCE_HOST', true),

    // Rescue switch: add-ons, themes, edited templates, custom CSS and custom HTML are all ignored.
    'safe_mode' => (bool) env('FORUM_SAFE_MODE', false),

    // Where add-ons live (null = addons/ in the project root).
    'addons_path' => null,

    // Where the web installer writes the environment file (null = .env in the project root).
    'install' => ['env_path' => null, 'sqlite_path' => null],

    // A member counts as online this long after the last request. An open page reports in by itself
    // (live alerts), so a short span is enough and somebody who closed the forum disappears quickly.
    'online_minutes' => 5,
    'unread_days' => 30,
];
