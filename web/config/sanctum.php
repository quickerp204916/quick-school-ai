<?php

/*
| Institution API tokens (Flutter app). Other Sanctum settings use the package defaults.
| guard => []: the API accepts bearer tokens only, never a browser session, so every API call
| carries a token that is checked against the institution it was issued for.
*/
return [
    'guard' => [],
    'expiration' => null,   // each token gets its own expiry (90 days) when issued
];
