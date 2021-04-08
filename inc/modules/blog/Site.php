<?php

/**
 * This file is part of Batflat ~ the lightweight, fast and easy CMS
 *
 * @author       Paweł Klockiewicz <klockiewicz@sruu.pl>
 * @author       Wojciech Król <krol@sruu.pl>
 * @copyright    2017 Paweł Klockiewicz, Wojciech Król <Sruu.pl>
 * @license      https://batflat.org/license
 * @link         https://batflat.org
 */

namespace Inc\Modules\Blog;

use DateTime;
use Inc\Core\SiteModule;

class Site extends SiteModule
{
    public function init()
    {
        if (isset($_GET['search'])) {
            redirect(url('blog/szukaj/' . urlencode(strip_tags($_GET['search']))));
        }

        $slug = parseURL();
        if (count($slug) == 3 && $slug[0] == 'blog' && $slug[1] == 'wpis') {
            $row = $this->db('blog')->where('status', '>=', 1)->where('published_at', '<=', time())->where('slug', $slug[2])->oneArray();
            if ($row) {
                $this->core->loadLanguage($row['lang']);
            }
        }

        $this->tpl->set('latestPosts', function () {
            return $this->_getLatestPosts();
        });
        $this->tpl->set('allTags', function () {
            return $this->_getAllTags();
        });
    }

    public function routes()
    {
        $this->route('blog', '_importAllPosts');
        $this->route('blog/(:int)', '_importAllPosts');
        $this->route('blog/wpis/(:str)', '_importPost');
        $this->route('blog/temat/(:str)', '_importTagPosts');
        $this->route('blog/temat/(:str)/(:int)', '_importTagPosts');
        $this->route('blog/autor/(:str)', '_importUserPosts');
        $this->route('blog/autor/(:str)/(:int)', '_importUserPosts');
        $this->route('blog/szukaj/(:any)', '_importSearchPosts');
        $this->route('blog/szukaj/(:any)/(:int)', '_importSearchPosts');
        $this->route('blog/feed/(:str)', '_generateRSS');
        $this->route('tematy', '_importTagsPage');
        $this->route('tematy/(:int)', '_importTagsPage');
    }

    public function _getLatestPosts()
    {
        $limit = $this->settings('blog.latestPostsCount');
        $rows = $this->db('blog')
            ->leftJoin('users', 'users.id = blog.user_id')
            ->where('status', 2)
            ->where('published_at', '<=', time())
            ->where('lang', $_SESSION['lang'])
            ->desc('published_at')
            ->limit($limit)
            ->select(['blog.id', 'blog.title', 'blog.slug', 'blog.intro', 'blog.content', 'users.username', 'users.fullname'])
            ->toArray();

        foreach ($rows as &$row) {
            $this->filterRecord($row);
        }

        return $rows;
    }

    public function _getAllTags()
    {
        $limit = $this->settings('blog.popularTagsCount');
        $rows = $this->db('blog_tags')
            ->leftJoin('blog_tags_relationship', 'blog_tags.id = blog_tags_relationship.tag_id')
            ->leftJoin('blog', 'blog.id = blog_tags_relationship.blog_id')
            ->where('blog.status', 2)
            ->where('blog.lang', $_SESSION['lang'])
            ->where('blog.published_at', '<=', time())
            ->select(['blog_tags.name', 'blog_tags.slug', 'count' => 'COUNT(blog_tags.name)'])
            ->group('blog_tags.name')
            ->desc('count')
            ->limit($limit)
            ->toArray();

        return $rows;
    }

    /**
     * get single post data
     */
    public function _importPost($slug = null)
    {
        $assign = [];
        if (!empty($slug)) {
            if ($this->core->loginCheck()) {
                $row = $this->db('blog')->where('slug', $slug)->oneArray();
            } else {
                $row = $this->db('blog')->where('status', '>=', 1)->where('published_at', '<=', time())->where('slug', $slug)->oneArray();
            }

            if (!empty($row)) {
                // get dependences
                $row['author'] = $this->db('users')->where('id', $row['user_id'])->oneArray();
                $row['author']['name'] = !empty($row['author']['fullname']) ? $row['author']['fullname'] : $row['author']['username'];
                $row['author']['avatar'] = url(UPLOADS . '/users/' . $row['author']['avatar']);
                $row['cover_url'] = $row['cover_photo'] ? url(UPLOADS . '/blog/' . $row['cover_photo']) . '?' . $row['published_at'] : NULL;
                $row['cover_mobile_url'] = $row['cover_mobile'] ? url(UPLOADS . '/blog/' . $row['cover_mobile']) . '?' . $row['published_at'] : NULL;

                $row['url'] = url('blog/wpis/' . $row['slug']);
                $row['disqus_identifier'] = md5($row['id'] . $row['url']);

                // tags
                $row['tags'] = $this->db('blog_tags')
                    ->leftJoin('blog_tags_relationship', 'blog_tags.id = blog_tags_relationship.tag_id')
                    ->where('blog_tags_relationship.blog_id', $row['id'])
                    ->toArray();
                if ($row['tags']) {
                    array_walk($row['tags'], function (&$tag) {
                        $tag['url'] = url('blog/temat/' . $tag['slug']);
                    });
                }

                $this->filterRecord($row);
                $assign = $row;

                // Markdown
                if (intval($assign['markdown'])) {
                    $parsedown = new \Inc\Core\Lib\Parsedown();
                    $assign['content'] = $parsedown->text($assign['content']);
                    $assign['intro'] = $parsedown->text($assign['intro']);
                }

                // date formatting
                $assign['datetime'] = (new \DateTime(date("YmdHis", $assign['published_at'])))->format("Y-m-d H:i:s");
                $assign['published_at'] = (new \DateTime(date("YmdHis", $assign['published_at'])))->format($this->settings('blog.dateformat'));
                $keys = array_keys($this->core->lang['blog']);
                $vals = array_values($this->core->lang['blog']);
                $assign['published_at'] = str_replace($keys, $vals, strtolower($assign['published_at']));

                $this->setTemplate("post.html");
                $this->tpl->set('page', ['title' => $assign['title'], 'desc' => trim(mb_strimwidth(htmlspecialchars(strip_tags(preg_replace('/\{(.*?)\}/', null, $assign['content']))), 0, 155, "...", "utf-8"))]);
                $this->tpl->set('post', $assign);
                $this->tpl->set('blog', [
                    'title' => $this->settings('blog.title'),
                    'desc' => $this->settings('blog.desc')
                ]);
            } else {
                return $this->core->module->pages->get404();
            }
        }

        $this->core->append('<link rel="alternate" type="application/rss+xml" title="RSS" href="' . url(['blog', 'feed', $row['lang']]) . '">', 'header');
        $this->core->append('<meta property="og:url" content="' . url(['blog', 'wpis', $row['slug']]) . '">', 'header');
        $this->core->append('<meta property="og:type" content="article">', 'header');
        $this->core->append('<meta property="og:title" content="' . $row['title'] . '">', 'header');
        $this->core->append('<meta property="og:description" content="' . trim(mb_strimwidth(htmlspecialchars(strip_tags(preg_replace('/\{(.*?)\}/', null, $assign['content']))), 0, 155, "...", "utf-8")) . '">', 'header');
        if (!empty($row['cover_photo'])) {
            $this->core->append('<meta property="og:image" content="' . url(UPLOADS . '/blog/' . $row['cover_mobile']) . '?' . $row['published_at'] . '">', 'header');
        }

        $this->core->append($this->draw('disqus.html', ['isPost' => true]), 'footer');
    }

    /**
     * get array with all posts
     */
    public function _importAllPosts($page = 1)
    {
        $page = max($page, 1);
        $perpage = $this->settings('blog.perpage');
        $rows = $this->db('blog')
            ->where('status', 2)
            ->where('published_at', '<=', time())
            ->where('lang', $_SESSION['lang'])
            ->limit($perpage)->offset(($page - 1) * $perpage)
            ->desc('published_at')
            ->toArray();

        $assign = [
            'title' => $this->settings('blog.title'),
            'desc' => $this->settings('settings.description'),
            'posts' => []
        ];
        foreach ($rows as $row) {
            // get dependences
            $row['author'] = $this->db('users')->where('id', $row['user_id'])->oneArray();
            $row['author']['name'] = !empty($row['author']['fullname']) ? $row['author']['fullname'] : $row['author']['username'];
            $row['cover_url'] = $row['cover_thumbnail'] ? url(UPLOADS . '/blog/' . $row['cover_thumbnail']) . '?' . $row['published_at'] : NULL;

            // tags
            $row['tags'] = $this->db('blog_tags')
                ->leftJoin('blog_tags_relationship', 'blog_tags.id = blog_tags_relationship.tag_id')
                ->where('blog_tags_relationship.blog_id', $row['id'])
                ->toArray();

            if ($row['tags']) {
                array_walk($row['tags'], function (&$tag) {
                    $tag['url'] = url('blog/temat/' . $tag['slug']);
                });
            }

            // date formatting
            $row['published_at'] = $this->createDate($row['published_at']);
            $keys = array_keys($this->core->lang['blog']);
            $vals = array_values($this->core->lang['blog']);
            $row['published_at']['month'] = str_replace($keys, $vals, strtolower($row['published_at']['month']));

            // generating URLs
            $row['url'] = url('blog/wpis/' . $row['slug']);
            $row['disqus_identifier'] = md5($row['id'] . $row['url']);

            if (!empty($row['intro'])) {
                $row['content'] = $row['intro'];
            } else {
                $row['content'] = substr($row['content'], 0, 500);
            }

            if (intval($row['markdown'])) {
                if (!isset($parsedown)) {
                    $parsedown = new \Inc\Core\Lib\Parsedown();
                }
                $row['content'] = $parsedown->text($row['content']);
            }

            $row['content'] = strip_tags($row['content']);

            $this->filterRecord($row);
            $assign['posts'][$row['id']] = $row;
        }

        $count = $this->db('blog')->where('status', 2)->where('published_at', '<=', time())->where('lang', $_SESSION['lang'])->count();

        $this->_pagination($page, $perpage, $count, 'blog');

        $this->setTemplate("blog.html");

        $this->tpl->set('page', ['title' => $assign['title'], 'desc' => $assign['desc']]);
        $this->tpl->set('blog', $assign);

        $this->core->append('<link rel="alternate" type="application/rss+xml" title="RSS" href="' . url(['blog', 'feed', $_SESSION['lang']]) . '">', 'header');
        $this->core->append($this->draw('disqus.html', ['isBlog' => true]), 'footer');

        $this->_addOGheaders($this->settings('settings.title'), $this->settings('settings.description'), url());
    }

    public function _importTagsPage($page = 1)
    {
        $page = max($page, 1);
        $perpage = $this->settings('blog.subjects_perpage');

        $rows = $this->db('blog_tags')
            ->select('blog_tags.id')
            ->leftJoin('blog_tags_relationship', 'blog_tags.id = blog_tags_relationship.tag_id')
            ->leftJoin('blog', 'blog.id = blog_tags_relationship.blog_id')
            ->where('blog.status', 2)
            ->where('blog.lang', $_SESSION['lang'])
            ->where('blog.published_at', '<=', time())
            ->select(['blog_tags.name', 'blog_tags.slug', 'count' => 'COUNT(blog_tags.name)'])
            ->group('blog_tags.name')
            ->desc('count')
            ->limit($perpage)->offset(($page - 1) * $perpage)
            ->toArray();


        $assign = [
            'title' => $this->settings('blog.subjects_title'),
            'desc' => $this->settings('blog.subjects_desc'),
            'items' => []
        ];

        foreach ($rows as $row) {
            $posts = $this->db('blog')
                ->select(['blog.title', 'blog.slug', 'blog.cover_thumbnail', 'blog.published_at'])
                ->leftJoin('blog_tags_relationship', 'blog_tags_relationship.blog_id = blog.id')
                ->where('blog_tags_relationship.tag_id', $row['id'])
                ->where('blog.lang', $_SESSION['lang'])
                ->where('blog.status', 2)->where('blog.published_at', '<=', time())
                ->limit(3)
                ->desc('blog.published_at')
                ->toArray();

            foreach ($posts as &$post) {
                $post['postURL'] = url(['blog', 'wpis', $post['slug']]);
            }

            $row['cover_url'] = isset($posts[0]['cover_thumbnail']) ? url(UPLOADS . '/blog/' . $posts[0]['cover_thumbnail']) . '?' . $posts[0]['published_at'] : NULL;
            $row['posts'] = $posts;

            $assign['items'][] = $row;
        }

        $countQuery = $this->db()->pdo()->prepare("SELECT COUNT(*) AS count FROM (SELECT COUNT(*) AS count FROM blog_tags LEFT JOIN blog_tags_relationship ON blog_tags.id = blog_tags_relationship.tag_id LEFT JOIN blog ON blog.id = blog_tags_relationship.blog_id WHERE blog.status = ? AND blog.lang = ? AND blog.published_at <= ? GROUP BY blog_tags.name)");
        $countQuery->execute([2, $_SESSION['lang'], time()]);
        $count = $countQuery->fetchAll();
        $count = $count[0]['count'];

        $this->_pagination($page, $perpage, $count, 'tematy');

        $this->setTemplate("tematy.html");
        $this->tpl->set('page', ['title' => $assign['title'], 'desc' => $assign['desc']]);
        $this->tpl->set('tematy', $assign);

        $this->_addOGheaders($assign['title'], $assign['desc'], url(['tematy']));
    }



    /**
     * get array with all posts
     */
    public function _importTagPosts($slug, $page = 1)
    {
        $page = max($page, 1);
        $perpage = $this->settings('blog.perpage');

        if (!($tag = $this->db('blog_tags')->oneArray('slug', $slug))) {
            return $this->core->module->pages->get404();
        }

        $rows = $this->db('blog')
            ->leftJoin('blog_tags_relationship', 'blog_tags_relationship.blog_id = blog.id')
            ->where('blog_tags_relationship.tag_id', $tag['id'])
            ->where('lang', $_SESSION['lang'])
            ->where('status', 2)->where('published_at', '<=', time())
            ->limit($perpage)
            ->offset(($page - 1) * $perpage)
            ->desc('published_at')
            ->toArray();

        $assign = [
            'title' => $this->settings('blog.title'),
            'desc' => $this->settings('blog.desc'),
            'header' => 'Temat: ' . $tag['name'],
            'posts' => []
        ];
        foreach ($rows as $row) {
            // get dependences
            $row['author'] = $this->db('users')->where('id', $row['user_id'])->oneArray();
            $row['author']['name'] = !empty($row['author']['fullname']) ? $row['author']['fullname'] : $row['author']['username'];

            $row['cover_url'] = $row['cover_thumbnail'] ? url(UPLOADS . '/blog/' . $row['cover_thumbnail']) . '?' . $row['published_at'] : NULL;

            // tags
            $row['tags'] = $this->db('blog_tags')
                ->leftJoin('blog_tags_relationship', 'blog_tags.id = blog_tags_relationship.tag_id')
                ->where('blog_tags_relationship.blog_id', $row['id'])
                ->toArray();

            if ($row['tags']) {
                array_walk($row['tags'], function (&$tag) {
                    $tag['url'] = url('blog/temat/' . $tag['slug']);
                });
            }

            // date formatting
            $row['published_at'] = $this->createDate($row['published_at']);
            $keys = array_keys($this->core->lang['blog']);
            $vals = array_values($this->core->lang['blog']);
            $row['published_at']['month'] = str_replace($keys, $vals, strtolower($row['published_at']['month']));

            // generating URLs
            $row['url'] = url('blog/wpis/' . $row['slug']);
            $row['disqus_identifier'] = md5($row['id'] . $row['url']);

            if (!empty($row['intro'])) {
                $row['content'] = $row['intro'];
            } else {
                $row['content'] = substr($row['content'], 0, 500);
            }

            $row['content'] = strip_tags($row['content']);

            if (intval($row['markdown'])) {
                if (!isset($parsedown)) {
                    $parsedown = new \Inc\Core\Lib\Parsedown();
                }
                $row['content'] = $parsedown->text($row['content']);
            }

            $this->filterRecord($row);
            $assign['posts'][$row['id']] = $row;
        }

        $count = $this->db('blog')->leftJoin('blog_tags_relationship', 'blog_tags_relationship.blog_id = blog.id')->where('status', 2)->where('lang', $_SESSION['lang'])->where('published_at', '<=', time())->where('blog_tags_relationship.tag_id', $tag['id'])->count();

        $this->_pagination($page, $perpage, $count, 'blog/temat' . $slug);

        $this->setTemplate("blog.html");

        $this->tpl->set('page', ['title' => $assign['title'], 'desc' => $assign['desc']]);
        $this->tpl->set('blog', $assign);

        $this->core->append($this->draw('disqus.html', ['isBlog' => true]), 'footer');

        $this->_addOGheaders($assign['header'], $assign['desc'], url(['blog', 'temat', $tag['slug']]));
    }

    public function _importUserPosts($id, $page = 1)
    {
        $page = max($page, 1);
        $perpage = $this->settings('blog.perpage');

        if (!($user = $this->db('users')->oneArray('id', $id))) {
            return $this->core->module->pages->get404();
        }

        $rows = $this->db('blog')
            ->where('user_id', $user['id'])
            ->where('lang', $_SESSION['lang'])
            ->where('status', 2)->where('published_at', '<=', time())
            ->limit($perpage)
            ->offset(($page - 1) * $perpage)
            ->desc('published_at')
            ->toArray();

        $assign = [
            'title' => $this->settings('blog.title'),
            'header' => 'Autor: ' . $user['fullname'],
            'desc' => $this->settings('blog.desc'),
            'posts' => []
        ];
        foreach ($rows as $row) {
            // get dependences
            $row['author'] = $this->db('users')->where('id', $row['user_id'])->oneArray();
            $row['author']['name'] = !empty($row['author']['fullname']) ? $row['author']['fullname'] : $row['author']['username'];

            $row['cover_url'] = $row['cover_thumbnail'] ? url(UPLOADS . '/blog/' . $row['cover_thumbnail']) . '?' . $row['published_at'] : NULL;

            // tags
            $row['tags'] = $this->db('blog_tags')
                ->leftJoin('blog_tags_relationship', 'blog_tags.id = blog_tags_relationship.tag_id')
                ->where('blog_tags_relationship.blog_id', $row['id'])
                ->toArray();

            if ($row['tags']) {
                array_walk($row['tags'], function (&$tag) {
                    $tag['url'] = url('blog/temat/' . $tag['slug']);
                });
            }

            // date formatting
            $row['published_at'] = $this->createDate($row['published_at']);
            $keys = array_keys($this->core->lang['blog']);
            $vals = array_values($this->core->lang['blog']);
            $row['published_at']['month'] = str_replace($keys, $vals, strtolower($row['published_at']['month']));

            // generating URLs
            $row['url'] = url('blog/wpis/' . $row['slug']);
            $row['disqus_identifier'] = md5($row['id'] . $row['url']);

            if (!empty($row['intro'])) {
                $row['content'] = $row['intro'];
            } else {
                $row['content'] = substr($row['content'], 0, 500);
            }

            $row['content'] = strip_tags($row['content']);

            if (intval($row['markdown'])) {
                if (!isset($parsedown)) {
                    $parsedown = new \Inc\Core\Lib\Parsedown();
                }
                $row['content'] = $parsedown->text($row['content']);
            }

            $this->filterRecord($row);
            $assign['posts'][$row['id']] = $row;
        }

        $count = $this->db('blog')->where('status', 2)->where('published_at', '<=', time())->where('lang', $_SESSION['lang'])->where('user_id', $user['id'])->count();

        $this->_pagination($page, $perpage, $count, 'blog/autor/' . $user['id']);

        $this->setTemplate("blog.html");

        $this->tpl->set('page', ['title' => $assign['title'], 'desc' => $assign['desc']]);
        $this->tpl->set('blog', $assign);

        $this->core->append($this->draw('disqus.html', ['isBlog' => true]), 'footer');

        $this->_addOGheaders($assign['header'], $assign['desc'], url(['blog', 'user', $id]));
    }

    public function _importSearchPosts($phrase, $page = 1)
    {
        // Fix to incorrect routing work
        $splitPhrase = explode('/', $phrase);
        if (isset($splitPhrase[1])) {
            $phrase = $splitPhrase[0];
            $page = intval($splitPhrase[1]);
        }

        $originalPhrase = $phrase;
        $phrase = urldecode($phrase);
        $phrase = strip_tags($phrase);
        $phrase = htmlentities($phrase);

        $page = max($page, 1);
        $perpage = $this->settings('blog.perpage');

        $blog = $this->db()->pdo()->prepare("SELECT * FROM blog WHERE lang = ? AND status = ? AND (title LIKE ? OR content LIKE ?) ORDER BY published_at LIMIT ? OFFSET ?");
        $blog->execute([$_SESSION['lang'], 2, '%' . $phrase . '%', '%' . $phrase . '%', $perpage, ($page - 1) * $perpage]);
        $rows = $blog->fetchAll();

        $assign = [
            'title' => $this->settings('blog.title'),
            'header' => 'Szukaj: ' . $phrase,
            'desc' => $this->settings('blog.desc'),
            'posts' => []
        ];
        foreach ($rows as $row) {
            // get dependences
            $row['author'] = $this->db('users')->where('id', $row['user_id'])->oneArray();
            $row['author']['name'] = !empty($row['author']['fullname']) ? $row['author']['fullname'] : $row['author']['username'];

            $row['cover_url'] = $row['cover_thumbnail'] ? url(UPLOADS . '/blog/' . $row['cover_thumbnail']) . '?' . $row['published_at'] : NULL;

            // tags
            $row['tags'] = $this->db('blog_tags')
                ->leftJoin('blog_tags_relationship', 'blog_tags.id = blog_tags_relationship.tag_id')
                ->where('blog_tags_relationship.blog_id', $row['id'])
                ->toArray();

            if ($row['tags']) {
                array_walk($row['tags'], function (&$tag) {
                    $tag['url'] = url('blog/temat/' . $tag['slug']);
                });
            }

            // date formatting
            $row['published_at'] = $this->createDate($row['published_at']);
            $keys = array_keys($this->core->lang['blog']);
            $vals = array_values($this->core->lang['blog']);
            $row['published_at']['month'] = str_replace($keys, $vals, strtolower($row['published_at']['month']));

            // generating URLs
            $row['url'] = url('blog/wpis/' . $row['slug']);
            $row['disqus_identifier'] = md5($row['id'] . $row['url']);

            if (!empty($row['intro'])) {
                $row['content'] = $row['intro'];
            } else {
                $row['content'] = substr($row['content'], 0, 500);
            }

            $row['content'] = strip_tags($row['content']);

            if (intval($row['markdown'])) {
                if (!isset($parsedown)) {
                    $parsedown = new \Inc\Core\Lib\Parsedown();
                }
                $row['content'] = $parsedown->text($row['content']);
            }

            $this->filterRecord($row);
            $assign['posts'][$row['id']] = $row;
        }

        $blogCount = $this->db()->pdo()->prepare("SELECT COUNT(*) AS count FROM blog WHERE lang = ? AND status = ? AND (title LIKE ? OR content LIKE ?)");
        $blogCount->execute([$_SESSION['lang'], 2, '%' . $phrase . '%', '%' . $phrase . '%']);
        $count = $blogCount->fetch();

        $this->_pagination($page, $perpage, $count["count"], 'blog/szukaj/' . $originalPhrase);

        $this->setTemplate("blog.html");

        $this->tpl->set('page', ['title' => $assign['title'], 'desc' => $assign['desc']]);
        $this->tpl->set('blog', $assign);

        $this->core->append($this->draw('disqus.html', ['isBlog' => true]), 'footer');

        $this->_addOGheaders($assign['header'], $assign['desc'], url(['blog', 'search', $originalPhrase]));
    }

    public function _generateRSS($lang)
    {
        header('Content-type: application/xml');
        $this->setTemplate(false);

        $rows = $this->db('blog')
            ->where('status', 2)
            ->where('published_at', '<=', time())
            ->where('lang', $lang)
            ->limit(5)
            ->desc('published_at')
            ->toArray();

        if (!empty($rows)) {
            foreach ($rows as &$row) {
                if (!empty($row['intro'])) {
                    $row['content'] = $row['intro'];
                }

                $row['content'] = preg_replace('/{(.*?)}/', '', html_entity_decode(strip_tags($row['content'])));
                $row['url'] = url('blog/wpis/' . $row['slug']);
                $row['cover_url'] = url(UPLOADS . '/blog/' . $row['cover_mobile']) . '?' . $row['published_at'];
                $row['published_at'] = (new \DateTime(date("YmdHis", $row['published_at'])))->format('D, d M Y H:i:s O');

                $this->filterRecord($row);
            }
            echo '<?xml version="1.0" encoding="UTF-8" ?>' . "\r\n";
            echo $this->draw('feed.xml', ['posts' => $rows]);
        }
    }

    protected function filterRecord(array &$post)
    {
        $post['title'] = htmlspecialchars($post['title']);
    }

    protected function createDate($timestamp)
    {
        $date = (new \DateTime(date("YmdHis", $timestamp)));
        return [
            'time' => $date->format('Y-m-d H:i:s'),
            'day' => $date->format('d'),
            'month' => $date->format('M'),
            'year' => $date->format('Y')
        ];
    }

    protected function _pagination($page, $perpage, $count, $url)
    {
        $pages = ceil($count / $perpage);
        $pagesURLs = [];
        for ($i = 1; $i <= $pages; $i++) {
            $pagesURLs[] = [
                'title' => $i,
                'url' => url($url . '/' . $i)
            ];
        }

        $pagination = [
            'prev' => ($page > 1 ? url($url . '/' . ($page - 1)) : NULL),
            'next' => ($page < $count / $perpage ? url($url . '/' . ($page + 1)) : NULL),
            'current' => $page,
            'count' => $pages,
            'pages' => $pagesURLs
        ];
        $this->tpl->set('pagination', $pagination);
    }

    protected function _addOGheaders($title, $desc, $url)
    {
        $this->core->append('<meta property="og:url" content="' . $url . '">', 'header');
        $this->core->append('<meta property="og:type" content="blog">', 'header');
        $this->core->append('<meta property="og:title" content="' . $title . '">', 'header');
        $this->core->append('<meta property="og:description" content="' . $desc . '">', 'header');
        $this->core->append('<meta property="og:image" content="' . url(THEMES . '/ideal/img/ogimage.jpg') . '">', 'header');
    }
}
