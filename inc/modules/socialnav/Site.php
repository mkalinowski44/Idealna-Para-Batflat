<?php

namespace Inc\Modules\Socialnav;

use Inc\Core\SiteModule;

class Site extends SiteModule
{
    public function init()
    {
        $this->_insertMenu();
    }

    /**
     * get nav data
     */
    private function _insertMenu()
    {
        $assign = [];
        $homepage = $this->settings('settings', 'homepage');

        $lang_prefix = $this->core->lang['name'];
        if ($lang_prefix != $this->settings('settings', 'lang_site')) {
            $lang_prefix = explode('_', $lang_prefix)[0];
        } else {
            $lang_prefix = null;
        }

        // get nav
        $nav = $this->db('navs')->where('name', 'social')->oneArray();
        // get nav children
        $items = $this->db('navs_items')->leftJoin('pages', 'pages.id = navs_items.page')->where('navs_items.nav', $nav['id'])->where('navs_items.lang', $this->core->lang['name'])->asc('`order`')->select(['navs_items.*', 'pages.slug'])->toArray();

        if (count($items)) {
            // generate URL
            foreach ($items as &$item) {
                // if external URL field is empty, it means that it's a batflat page
                if (!$item['url']) {
                    if ($item['slug'] == $homepage) {
                        $item['url'] = $lang_prefix ? url([$lang_prefix]) : url('');
                    } else {
                        $item['url'] = $lang_prefix ? url([$lang_prefix, $item['slug']]) : url([$item['slug']]);
                    }
                } else {
                    $item['url'] = url($item['url']);

                    if ($item['url'] == url($homepage)) {
                        $item['url'] = url('');
                    }
                }
            }

            $assign = $items;
        } else {
            $assign = null;
        }

        $this->tpl->set('socialnav', $this->draw('nav.html', ['items' => $assign]));
    }
}
