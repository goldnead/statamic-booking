<?php

/*
 * Die Beschriftungen der Einstellungsseite.
 *
 * Feldschlüssel sind der Config-Pfad mit flachgelegten Punkten
 * (`signature.tolerance_seconds` → `signature_tolerance_seconds`).
 */

return [

    'permission_group' => 'Buchungen',
    'permission_manage' => 'Buchungs-Einstellungen verwalten',

    'groups' => [

        'endpoint' => [
            'title' => 'Endpunkt',
            'description' => 'Wie der Endpunkt eingehende Buchungen annimmt. Die Endpunkte selbst stehen weiterhin in der Datei config/statamic-booking.php, weil jeder von ihnen ein Geheimnis trägt und ein Geheimnis in einer Datenbankzeile in jedem Backup landet. Ebenso der Signatur-Header, das Verfahren und der Zeitstempel-Header: das ist der Protokollvertrag mit Cal.com.',
        ],

        'retention' => [
            'title' => 'Aufbewahrung',
            'description' => 'Wie lange eine Buchung bleibt. Eine Buchung trägt Name und Adresse, die Frist ist deshalb eine Datenschutzentscheidung.',
        ],

    ],

    'fields' => [

        'rate_limit' => [
            'label' => 'Anfragen je Minute und IP',
            'description' => 'Mehr Anfragen aus derselben Adresse werden abgewiesen. Zu niedrig heißt, dass ein Schwall echter Buchungen verloren geht; zu hoch heißt, dass ein Skript ungebremst schreiben darf. Gilt ab der nächsten Anfrage.',
        ],

        'signature_tolerance_seconds' => [
            'label' => 'Zulässiges Alter einer Signatur',
            'description' => 'Eine ältere Lieferung wird abgewiesen. Eine Signatur sagt nichts darüber, wann sie entstanden ist: ohne diese Grenze bleibt eine mitgeschnittene Lieferung für immer gültig. Leer schaltet die Prüfung ab, was nur für eine Gegenstelle ohne Zeitstempel vertretbar ist.',
        ],

        'keep_days' => [
            'label' => 'Buchungen aufbewahren (Tage)',
            'description' => 'Buchungen, deren Termin länger her ist, löscht php please booking:prune beim nächsten Lauf. Leer heißt: alles behalten, was das Gegenteil von Datenminimierung ist.',
        ],

    ],

];
