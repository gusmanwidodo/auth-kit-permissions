<?php

declare(strict_types=1);

return [
    /*
    | The access-control STATEMENT: resource => list of actions defined on it.
    | Static roles below can only grant actions declared here (typos fail fast).
    | This mirrors better-auth's createAccessControl statement object.
    */
    'statement' => [
        // 'post' => ['create', 'read', 'update', 'delete'],
        // 'member' => ['create', 'update', 'delete'],
    ],

    /*
    | STATIC roles: name => (resource => actions | '*'). Checks against these
    | are in-memory and ZERO-query. Use '*' to grant every action on a resource.
    | These are the fast path — define your common roles here.
    */
    'roles' => [
        // 'admin'  => ['post' => '*', 'member' => '*'],
        // 'author' => ['post' => ['create', 'read', 'update']],
        // 'viewer' => ['post' => ['read']],
    ],
];
