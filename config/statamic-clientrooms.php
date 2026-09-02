<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the shared files live
    |--------------------------------------------------------------------------
    |
    | The handle of the Statamic asset container that holds every room's
    | documents, one folder `room-<id>/` per room. `php please clientrooms:install`
    | creates it on the disk named below when it does not exist yet.
    |
    | The default disk is `local`, which has no public URL. Client documents
    | are never linked by their storage path; the client gets a signed URL that
    | expires. Put the container on a public disk only if you know why.
    |
    */

    'container' => env('CLIENTROOMS_CONTAINER', 'clientrooms'),

    'disk' => env('CLIENTROOMS_DISK', 'local'),

    /*
    | On a multi-brand install (goldnead/statamic-brand-context) each brand
    | gets its own container, `<container>-<brandId>`, so asset permissions
    | can be granted per brand. `clientrooms:install` creates them all.
    */

    /*
    |--------------------------------------------------------------------------
    | What may be uploaded
    |--------------------------------------------------------------------------
    |
    | File extensions a room accepts, checked at the upload endpoint and again
    | in `ClientRooms::attach()`. An empty list accepts everything, which you
    | do not want on a form staff use in a hurry.
    |
    */

    'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'mp3', 'mp4', 'zip'],

    /*
    |--------------------------------------------------------------------------
    | Opening a room automatically
    |--------------------------------------------------------------------------
    |
    | With goldnead/statamic-payments installed, a paid payment opens a room
    | for the buyer when at least one line of it is a product of one of these
    | kinds (`type` on the products table, from goldnead/statamic-products) or
    | one of these handles. A closed room is reopened; an open one is left as
    | it is. Without payments nothing happens here and rooms are opened by hand
    | or through the facade.
    |
    | `sessions` is the kind statamic-products gives a package of coaching
    | sessions. Add `cohort` if a group programme should open a room too.
    |
    */

    'open_on_product_types' => ['sessions'],

    'open_on_products' => [],

    /*
    |--------------------------------------------------------------------------
    | Who owns a room that was opened automatically
    |--------------------------------------------------------------------------
    |
    | A Statamic user id or e-mail address. Null leaves the room without an
    | owner until somebody picks one in the Control Panel.
    |
    */

    'default_owner' => env('CLIENTROOMS_DEFAULT_OWNER'),

    /*
    |--------------------------------------------------------------------------
    | Signed download links
    |--------------------------------------------------------------------------
    |
    | How long a link handed to the client stays valid, in minutes. The link is
    | produced fresh every time the room is rendered, so a short window costs
    | nothing.
    |
    */

    'download_ttl_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Timeline
    |--------------------------------------------------------------------------
    |
    | How many entries the room shows at most. With goldnead/statamic-leadhub
    | installed the merged contact timeline is used; without it a short list is
    | read from payments and bookings, when those tables exist.
    |
    */

    'timeline_limit' => 100,

];
