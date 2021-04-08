<?php

namespace Inc\Modules\Themeswitch;

use Inc\Core\SiteModule;

class Site extends SiteModule
{
    public function init()
    {
        $theme = 'light';

        if (isset($_COOKIE['theme'])) {
            $theme = $_COOKIE['theme'];
        }

        $this->tpl->set('theme', $theme);
    }
}
