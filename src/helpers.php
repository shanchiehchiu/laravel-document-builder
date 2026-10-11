<?php

declare(strict_types=1);

if (! function_exists('document_builder_route_name')) {
    /**
     * 套件路由的完整名稱：加上宿主設定的路由名稱前綴（document_builder.route.name）。
     * 例如前綴是 backend. 時，document_builder.types.index 會變成 backend.document_builder.types.index。
     */
    function document_builder_route_name(string $name): string
    {
        return config('document_builder.route.name', '').$name;
    }
}
