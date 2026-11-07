<?php
// Licence checks go through the Managed FreeScout hub, never straight to invAIse (same as
// MFS Essentials, card #194): a self-hosted FreeScout must not hold StackPros' invAIse credentials.
return [
    'hub_url'   => env('MFSORGANISATIONS_HUB_URL', 'https://app.managedfreescout.com'),
    'buy_url'   => env('MFSORGANISATIONS_BUY_URL', 'https://managedfreescout.com/mfsorganisations/'),
    'terms_url' => 'https://managedfreescout.com/terms-and-conditions/',

    // €9/year, invAIse product line "mfsorganisations" (card #423). Only for development can
    // MFSORGANISATIONS_ENFORCE_LICENSE=false switch the check off.
    'enforce_license' => env('MFSORGANISATIONS_ENFORCE_LICENSE', true),

    // Free-mail domains: never linked to an organisation by domain. Admins edit the list under
    // Manage → Settings → MFS Organisations; this is the starting list.
    'freemail' => [
        'gmail.com', 'googlemail.com', 'outlook.com', 'outlook.nl', 'hotmail.com', 'hotmail.nl',
        'live.com', 'live.nl', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'yahoo.com',
        'yahoo.co.uk', 'ymail.com', 'aol.com', 'proton.me', 'protonmail.com', 'pm.me',
        'gmx.com', 'gmx.net', 'gmx.de', 'web.de', 't-online.de', 'mail.com', 'zoho.com',
        'yandex.com', 'fastmail.com', 'tutanota.com', 'ziggo.nl', 'kpnmail.nl', 'kpnplanet.nl',
        'planet.nl', 'home.nl', 'xs4all.nl', 'hetnet.nl', 'casema.nl', 'chello.nl', 'quicknet.nl',
        'telfort.nl', 'tele2.nl', 'online.nl', 'upcmail.nl', 'zeelandnet.nl', 'telenet.be',
        'skynet.be', 'orange.fr', 'free.fr', 'laposte.net', 'wanadoo.fr', 'libero.it', 'btinternet.com',
    ],
];
