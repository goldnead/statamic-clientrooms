<?php

return [

    // Labels for the settings screen. The screen itself belongs to
    // statamic-brand-context; this addon supplies only the field list
    // (Support\Settings) and the words for it.

    'permission_manage_settings' => 'Manage client room settings',

    'groups' => [

        'files' => [
            'title' => 'Files in a room',
            'description' => 'What a room accepts, and how long a download link lives. Where the files are kept stays in config/statamic-clientrooms.php: moving the container or the disk means moving the documents that are already there, and a field that only redirects the pointer turns every existing room into an empty one.',
        ],

        'tasks' => [
            'title' => 'Tasks',
            'description' => 'The vocabulary the task form offers.',
        ],

        'opening' => [
            'title' => 'Rooms that open by themselves',
            'description' => 'With goldnead/statamic-payments installed, a paid payment opens a room for the buyer. Without it nothing happens here and rooms are opened by hand or through the facade.',
        ],

        'timeline' => [
            'title' => 'Timeline',
            'description' => 'How much history a room shows.',
        ],

    ],

    'fields' => [

        'allowed_extensions' => [
            'label' => 'Accepted file extensions',
            'description' => 'What may be uploaded, checked at the upload endpoint and again when the file is attached. Removing an extension only stops new uploads; what is already in a room stays there. An empty list accepts everything, which is not what you want on a form staff use in a hurry.',
        ],
        'member_upload_max_kb' => [
            'label' => 'Largest single upload (KB)',
            'description' => 'The cap on one file a client sends through the members endpoint. Generous by default because these are usually recordings. PHP\'s own upload_max_filesize on the server still has the last word: a higher number here does not raise it.',
        ],
        'download_ttl_minutes' => [
            'label' => 'Life of a download link (minutes)',
            'description' => 'How long a link handed to the client stays valid. The link is produced fresh every time the room is rendered, so a short window costs nothing. Shortening it does not invalidate links already sent; it applies from the next render.',
        ],

        'task_types' => [
            'label' => 'Kinds of task',
            'description' => 'What the task form offers in its type field, and the only values it accepts there. These are handles, not labels: a handle with a translation under statamic-clientrooms::messages.task_type_<handle> is shown with it, anything else exactly as written here. Removing a kind changes no existing task, it only stops being offered. An empty list turns the field off.',
        ],

        'open_on_product_types' => [
            'label' => 'Product kinds that open a room',
            'description' => 'A paid payment opens a room when at least one of its lines is a product of one of these kinds. A closed room is reopened; an open one is left as it is. "sessions" is the kind statamic-products gives a package of coaching sessions.',
        ],
        'open_on_products' => [
            'label' => 'Individual products that open a room',
            'description' => 'The same, per product handle, for the case where a single offer deserves a room but its kind does not.',
        ],
        'default_owner' => [
            'label' => 'Owner of an automatically opened room',
            'description' => 'A Statamic user id or e-mail address. Empty leaves the room without an owner until somebody picks one in the Control Panel. The value is written when the room opens and does not change existing rooms: changing it here changes it for the next purchases.',
        ],

        'timeline_limit' => [
            'label' => 'Entries on the timeline',
            'description' => 'How many events a room shows at most. Nothing is deleted, less is shown.',
        ],

    ],

];
