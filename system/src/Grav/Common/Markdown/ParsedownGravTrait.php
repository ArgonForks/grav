<?php
namespace Grav\Common\Markdown;

use Grav\Common\Config\Config;
use Grav\Common\Debugger;
use Grav\Common\GravTrait;
use Grav\Common\Page\Medium\Medium;
use Grav\Common\Uri;
use Grav\Common\Utils;

/**
 * A trait to add some custom processing to the identifyLink() method in Parsedown and ParsedownExtra
 */
trait ParsedownGravTrait
{
    use GravTrait;
    protected $page;
    protected $pages;
    protected $base_url;
    protected $pages_dir;
    protected $special_chars;

    protected $twig_link_regex = '/\!*\[(?:.*)\]\((\{([\{%#])\s*(.*?)\s*(?:\2|\})\})\)/';

    /**
     * Initialiazation function to setup key variables needed by the MarkdownGravLinkTrait
     *
     * @param $page
     * @param $defaults
     */
    protected function init($page, $defaults)
    {
        $this->page = $page;
        $this->pages = self::getGrav()['pages'];
        $this->BlockTypes['{'] [] = "TwigTag";
        $this->base_url = rtrim(self::getGrav()['base_url'] . self::getGrav()['pages']->base(), '/');
        $this->pages_dir = self::getGrav()['locator']->findResource('page://');
        $this->special_chars = array('>' => 'gt', '<' => 'lt', '"' => 'quot');

        if ($defaults == null) {
            $defaults = self::getGrav()['config']->get('system.pages.markdown');
        }

        $this->setBreaksEnabled($defaults['auto_line_breaks']);
        $this->setUrlsLinked($defaults['auto_url_links']);
        $this->setMarkupEscaped($defaults['escape_markup']);
        $this->setSpecialChars($defaults['special_chars']);
    }

    /**
     * Make the element function publicly accessible, Medium uses this to render from Twig
     *
     * @param  array  $Element
     * @return string markup
     */
    public function elementToHtml(array $Element)
    {
        return $this->element($Element);
    }

    /**
     * Setter for special chars
     *
     * @param $special_chars
     *
     * @return $this
     */
    function setSpecialChars($special_chars)
    {
        $this->special_chars = $special_chars;

        return $this;
    }

    /**
     * Ensure Twig tags are treated as block level items with no <p></p> tags
     */
    protected function blockTwigTag($Line)
    {
        if (preg_match('/[{%|{{|{#].*[#}|}}|%}]/', $Line['body'], $matches)) {
            $Block = array(
                'markup' => $Line['body'],
            );
            return $Block;
        }
    }

    protected function inlineSpecialCharacter($Excerpt)
    {
        if ($Excerpt['text'][0] === '&' && ! preg_match('/^&#?\w+;/', $Excerpt['text'])) {
            return array(
                'markup' => '&amp;',
                'extent' => 1,
            );
        }

        if (isset($this->special_chars[$Excerpt['text'][0]])) {
            return array(
                'markup' => '&'.$this->special_chars[$Excerpt['text'][0]].';',
                'extent' => 1,
            );
        }
    }

    protected function inlineImage($excerpt)
    {
        if (preg_match($this->twig_link_regex, $excerpt['text'], $matches)) {
            $excerpt['text'] = str_replace($matches[1], '/', $excerpt['text']);
            $excerpt = parent::inlineImage($excerpt);
            $excerpt['element']['attributes']['src'] = $matches[1];
            $excerpt['extent'] = $excerpt['extent'] + strlen($matches[1]) - 1;
            return $excerpt;
        } else {
            $excerpt = parent::inlineImage($excerpt);
        }

        // Some stuff we will need
        $actions = array();
        $media = null;

        // if this is an image
        if (isset($excerpt['element']['attributes']['src'])) {
            $alt = $excerpt['element']['attributes']['alt'] ?: '';
            $title = $excerpt['element']['attributes']['title'] ?: '';
            $class = isset($excerpt['element']['attributes']['class']) ? $excerpt['element']['attributes']['class'] : '';

            //get the url and parse it
            $url = parse_url(htmlspecialchars_decode($excerpt['element']['attributes']['src']));

            $path_parts = pathinfo($url['path']);

            // if there is no host set but there is a path, the file is local
            if (!isset($url['host']) && isset($url['path'])) {
                // get the local path to page media if possible
                if ($path_parts['dirname'] == $this->page->url()) {
                    $url['path'] = ltrim(str_replace($this->page->url(), '', $url['path']), '/');
                    // get the media objects for this page
                    $media = $this->page->media();

                } else {
                    // see if this is an external page to this one
                    $page_route = str_replace($this->base_url, '', $path_parts['dirname']);

                    $ext_page = $this->pages->dispatch($page_route, true);
                    if ($ext_page) {
                        $media = $ext_page->media();
                        $url['path'] = $path_parts['basename'];
                    }
                }

                // if there is a media file that matches the path referenced..
                if ($media && isset($media->all()[$url['path']])) {
                    // get the medium object
                    $medium = $media->all()[$url['path']];

                    // if there is a query, then parse it and build action calls
                    if (isset($url['query'])) {
                        $actions = array_reduce(explode('&', $url['query']), function ($carry, $item) {
                            $parts = explode('=', $item, 2);
                            $value = isset($parts[1]) ? $parts[1] : null;
                            $carry[] = [ 'method' => $parts[0], 'params' => $value ];

                            return $carry;
                        }, []);
                    }

                    // loop through actions for the image and call them
                    foreach ($actions as $action) {
                        $medium = call_user_func_array(array($medium, $action['method']), explode(',', $action['params']));
                    }

                    if (isset($url['fragment'])) {
                        $medium->urlHash($url['fragment']);
                    }

                    $excerpt['element'] = $medium->parseDownElement($title, $alt, $class);

                } else {
                    // not a current page media file, see if it needs converting to relative
                    $excerpt['element']['attributes']['src'] = Uri::buildUrl($url);
                }
            }
        }

        return $excerpt;
    }

    protected function inlineLink($excerpt)
    {
        // do some trickery to get around Parsedown requirement for valid URL if its Twig in there
        if (preg_match($this->twig_link_regex, $excerpt['text'], $matches)) {
            $excerpt['text'] = str_replace($matches[1], '/', $excerpt['text']);
            $excerpt = parent::inlineLink($excerpt);
            $excerpt['element']['attributes']['href'] = $matches[1];
            $excerpt['extent'] = $excerpt['extent'] + strlen($matches[1]) - 1;
            return $excerpt;
        } else {
            $excerpt = parent::inlineLink($excerpt);
        }

        // if this is a link
        if (isset($excerpt['element']['attributes']['href'])) {
            $url = parse_url(htmlspecialchars_decode($excerpt['element']['attributes']['href']));

            // if there is no scheme, the file is local
            if (!isset($url['scheme']) && (count($url) > 0)) {
                // convert the URl is required
                $excerpt['element']['attributes']['href'] = $this->convertUrl(Uri::buildUrl($url));
            }
        }

        return $excerpt;
    }

    /**
     * Converts links from absolute '/' or relative (../..) to a grav friendly format
     * @param  string $markdown_url the URL as it was written in the markdown
     * @return string               the more friendly formatted url
     */
    protected function convertUrl($markdown_url)
    {
        // if absolute and starts with a base_url move on
        if (Utils::startsWith($markdown_url, $this->base_url)) {
            return $markdown_url;
            // if contains only a fragment
        } elseif (Utils::startsWith($markdown_url, '#')) {
            return $markdown_url;
        } else {
            $target = null;
            // see if page is relative to this or absolute
            if (Utils::startsWith($markdown_url, '/')) {
                $normalized_path = Utils::normalizePath($this->pages_dir . $markdown_url);
                $normalized_url = Utils::normalizePath($this->base_url . $markdown_url);
            } else {
                $normalized_path = Utils::normalizePath($this->page->path() . '/' . $markdown_url);
                $normalized_url = $this->base_url . Utils::normalizePath($this->page->route() . '/' . $markdown_url);
            }

            $url_bits = parse_url($normalized_path);
            $full_path = $url_bits['path'];

            if (file_exists($full_path)) {
                $page_path = pathinfo($full_path, PATHINFO_DIRNAME);

                // get page instances and try to find one that fits
                $instances = $this->pages->instances();
                if (isset($instances[$full_path])) {
                    $target = $instances[$full_path];
                } elseif (isset($instances[$page_path])) {
                    $target = $instances[$page_path];
                }

                // if a page target is found...
                if ($target) {
                    $url_bits['path'] = $this->base_url . $target->route();
                    return Uri::buildUrl($url_bits);
                }
            }
            return $normalized_url;
        }
    }
}
