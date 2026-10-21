<?php

/*
 * Business details for Hijaz Hajj & Umrah. These are public contact details,
 * not secrets, so they live here rather than in .env. Read them with
 * config('hijaz.email') and so on; nothing displays them yet.
 */

return [

    'name' => 'Hijaz Hajj & Umrah',

    'email' => 'hijazumra@gmail.com',

    // Maldives mobile numbers. 'display' is how to show them, 'tel' is for tel: links.
    'phones' => [
        ['display' => '977 5123', 'tel' => '+9609775123'],
        ['display' => '990 6869', 'tel' => '+9609906869'],
    ],

];
