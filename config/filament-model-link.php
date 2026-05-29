<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Pill chain max depth
    |--------------------------------------------------------------------------
    |
    | Maximum number of ancestors walked via HasPillParent::pillParent().
    | Guards against accidental cycles in parent relationships.
    |
    */
    'pill_chain_max_depth' => 5,

    /*
    |--------------------------------------------------------------------------
    | Default ancestor label limit
    |--------------------------------------------------------------------------
    |
    | When rendering a pill chain, ancestor labels are truncated to this
    | character count to keep the combined chip compact. The target (last)
    | label is governed by the per-call $labelLimit argument.
    |
    */
    'ancestor_label_limit' => 20,

    /*
    |--------------------------------------------------------------------------
    | View types
    |--------------------------------------------------------------------------
    |
    | Default order of Filament resource actions tried when building a URL
    | for a related model. The first action the user can access wins.
    |
    */
    'default_view_types' => ['view', 'edit'],

    /*
    |--------------------------------------------------------------------------
    | Default fallback color
    |--------------------------------------------------------------------------
    |
    | Used for models that do not implement the HasPills contract.
    |
    */
    'default_color' => 'gray',
];
