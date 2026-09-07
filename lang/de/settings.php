<?php

return [

    // Beschriftungen der Einstellungs-Seite. Die Seite selbst gehört
    // statamic-brand-context; dieses Addon liefert nur die Feldliste
    // (Support\Settings) und die Wörter dazu.

    'permission_manage_settings' => 'Einstellungen der Klientenräume verwalten',

    'groups' => [

        'files' => [
            'title' => 'Dateien im Raum',
            'description' => 'Was ein Raum annimmt und wie lange ein Downloadlink lebt. Wo die Dateien liegen, steht weiterhin in config/statamic-clientrooms.php: Container und Ablage umzustellen heißt, die vorhandenen Dokumente zu bewegen, und ein Feld, das nur den Zeiger umlegt, macht aus jedem bestehenden Raum einen leeren.',
        ],

        'tasks' => [
            'title' => 'Aufgaben',
            'description' => 'Das Vokabular, das die Aufgabenmaske anbietet.',
        ],

        'opening' => [
            'title' => 'Räume, die sich von selbst öffnen',
            'description' => 'Mit goldnead/statamic-payments öffnet eine bezahlte Zahlung einen Raum für den Käufer. Ohne dieses Addon passiert hier nichts und Räume entstehen von Hand oder über die Fassade.',
        ],

        'timeline' => [
            'title' => 'Zeitachse',
            'description' => 'Wie viel Vergangenheit ein Raum zeigt.',
        ],

    ],

    'fields' => [

        'allowed_extensions' => [
            'label' => 'Erlaubte Dateiendungen',
            'description' => 'Was hochgeladen werden darf, geprüft am Upload und noch einmal beim Anhängen. Eine Endung streichen sperrt nur neue Uploads; was schon im Raum liegt, bleibt liegen. Eine leere Liste nimmt alles an, was Sie auf einem Formular, das Mitarbeitende in Eile bedienen, nicht wollen.',
        ],
        'member_upload_max_kb' => [
            'label' => 'Höchstgröße je Datei (KB)',
            'description' => 'Die Obergrenze für eine Datei, die ein Klient über die Mitglieder-Schnittstelle schickt. Großzügig gewählt, weil das meist Aufnahmen sind. PHPs eigenes upload_max_filesize auf dem Server hat trotzdem das letzte Wort: eine höhere Zahl hier hebt es nicht an.',
        ],
        'download_ttl_minutes' => [
            'label' => 'Laufzeit eines Downloadlinks (Minuten)',
            'description' => 'Wie lange ein an den Klienten gegebener Link gültig bleibt. Der Link wird bei jedem Aufbau des Raums neu erzeugt, ein kurzes Fenster kostet also nichts. Verkürzen entwertet keine bereits verschickten Links rückwirkend, sondern gilt ab dem nächsten Aufbau.',
        ],

        'task_types' => [
            'label' => 'Aufgabenarten',
            'description' => 'Was die Aufgabenmaske im Feld „Art" anbietet, und die einzigen Werte, die sie dort annimmt. Das sind Handles, keine Beschriftungen: ein Handle mit einer Übersetzung unter statamic-clientrooms::messages.task_type_<handle> wird damit angezeigt, jedes andere so, wie es hier steht. Eine Art zu streichen ändert keine bestehende Aufgabe, sie lässt sich danach nur nicht mehr neu vergeben. Eine leere Liste schaltet das Feld ab.',
        ],

        'open_on_product_types' => [
            'label' => 'Produktarten, die einen Raum öffnen',
            'description' => 'Eine bezahlte Zahlung öffnet einen Raum, wenn mindestens eine ihrer Zeilen ein Produkt dieser Art ist. Ein geschlossener Raum wird wieder geöffnet, ein offener bleibt, wie er ist. „sessions" ist die Art, die statamic-products einem Paket Coachingstunden gibt.',
        ],
        'open_on_products' => [
            'label' => 'Einzelne Produkte, die einen Raum öffnen',
            'description' => 'Dasselbe je Produkt-Handle, für den Fall, dass ein einzelnes Angebot einen Raum verdient, seine Art aber nicht.',
        ],
        'default_owner' => [
            'label' => 'Besitzer eines automatisch geöffneten Raums',
            'description' => 'Eine Statamic-Benutzer-ID oder E-Mail-Adresse. Leer lässt den Raum ohne Besitzer, bis jemand ihn im Control Panel übernimmt. Der Wert wird beim Öffnen eingetragen und ändert bestehende Räume nicht: wer hier wechselt, wechselt für die nächsten Käufe.',
        ],

        'timeline_limit' => [
            'label' => 'Einträge auf der Zeitachse',
            'description' => 'Wie viele Ereignisse ein Raum höchstens zeigt. Nichts wird gelöscht, es wird nur weniger angezeigt.',
        ],

    ],

];
