<?php
/**
 * @link https://github.com/gajus/dindent for the canonical source repository
 * @license https://github.com/gajus/dindent/blob/master/LICENSE BSD 3-Clause
 */

namespace Inc\Core\Lib;

/**
 * Indenter class
 */
class Indenter
{
    private $log = array();
    private $options = array(
            'indentation_character' => '    '
        );
    private $inline_elements = array('b', 'big', 'i', 'small', 'tt', 'abbr', 'acronym', 'cite', 'code', 'dfn', 'em', 'kbd', 'strong', 'samp', 'var', 'a', 'bdo', 'br', 'img', 'span', 'sub', 'sup');
    private $temporary_replacements_script = array();
    private $temporary_replacements_inline = array();

    const ELEMENT_TYPE_BLOCK = 0;
    const ELEMENT_TYPE_INLINE = 1;

    const MATCH_INDENT_NO = 0;
    const MATCH_INDENT_DECREASE = 1;
    const MATCH_INDENT_INCREASE = 2;
    const MATCH_DISCARD = 3;

    /**
     * @param array $options
     */
    public function __construct(array $options = array())
    {
        foreach ($options as $name => $value) {
            if (!array_key_exists($name, $this->options)) {
                trigger_error('Indenter: Unrecognized option.', E_USER_NOTICE);
            }

            $this->options[$name] = $value;
        }
    }

    /**
     * @param string $element_name Element name, e.g. "b".
     * @param ELEMENT_TYPE_BLOCK|ELEMENT_TYPE_INLINE $type
     * @return null
     */
    public function setElementType($element_name, $type)
    {
        if ($type === static::ELEMENT_TYPE_BLOCK) {
            $this->inline_elements = array_diff($this->inline_elements, array($element_name));
        } elseif ($type === static::ELEMENT_TYPE_INLINE) {
            $this->inline_elements[] = $element_name;
        } else {
            trigger_error('Indenter: Unrecognized element type.', E_USER_NOTICE);
        }

        $this->inline_elements = array_unique($this->inline_elements);
    }

    /**
     * @param string $input HTML input.
     * @return string Indented HTML.
     */
    public function indent($input)
    {
        $this->log = array();

        // Dindent does not indent <script> body. Instead, it temporary removes it from the code, indents the input, and restores the script body.
        if (preg_match_all('/<script\b[^>]*>([\s\S]*?)<\/script>/mi', $input, $matches)) {
            $this->temporary_replacements_script = $matches[0];
            foreach ($matches[0] as $i => $match) {
                $input = str_replace($match, '<script>' . ($i + 1) . '</script>', $input);
            }
        }

        // Removing double whitespaces to make the source code easier to read.
        // $input = str_replace("\t", '', $input);
        // $input = preg_replace('/\s{2,}/', ' ', $input);

        // Remove inline elements and replace them with text entities.
        if (preg_match_all('/<(' . implode('|', $this->inline_elements) . ')[^>]*>(?:[^<]*)<\/\1>/', $input, $matches)) {
            $this->temporary_replacements_inline = $matches[0];
            foreach ($matches[0] as $i => $match) {
                $input = str_replace($match, '???' . ($i + 1) . '???', $input);
            }
        }

        $subject = $input;

        $output = '';

        $next_line_indentation_level = 0;

        do {
            $indentation_level = $next_line_indentation_level;

            $patterns = array(
                // block tag
                '/^(<([a-z]+)(?:[^>]*)>(?:[^<]*)<\/(?:\2)>)/' => static::MATCH_INDENT_NO,
                // DOCTYPE
                '/^<!([^>]*)>/' => static::MATCH_INDENT_NO,
                // tag with implied closing
                '/^<(input|link|meta|base|br|img|hr)([^>]*)>/' => static::MATCH_INDENT_NO,
                // opening tag
                '/^<[^\/]([^>]*)>/' => static::MATCH_INDENT_INCREASE,
                // closing tag
                '/^<\/([^>]*)>/' => static::MATCH_INDENT_DECREASE,
                // self-closing tag
                '/^<(.+)\/>/' => static::MATCH_INDENT_DECREASE,
                // whitespace
                '/^(\s+)/' => static::MATCH_DISCARD,
                // text node
                '/([^<]+)/' => static::MATCH_INDENT_NO
            );
            $rules = array('NO', 'DECREASE', 'INCREASE', 'DISCARD');

            foreach ($patterns as $pattern => $rule) {
                if ($match = preg_match($pattern, $subject, $matches)) {
                    $this->log[] = array(
                        'rule' => $rules[$rule],
                        'pattern' => $pattern,
                        'subject' => $subject,
                        'match' => $matches[0]
                    );

                    $subject = mb_substr($subject, mb_strlen($matches[0]));

                    if ($rule === static::MATCH_DISCARD) {
                        break;
                    }

                    if ($rule === static::MATCH_INDENT_NO) {
                    } elseif ($rule === static::MATCH_INDENT_DECREASE) {
                        $next_line_indentation_level--;
                        $indentation_level--;
                    } else {
                        $next_line_indentation_level++;
                    }

                    if ($indentation_level < 0) {
                        $indentation_level = 0;
                    }

                    $output .= str_repeat($this->options['indentation_character'], $indentation_level) . $matches[0] . "\n";

                    break;
                }
            }
        } while ($match);

        $interpreted_input = '';
        foreach ($this->log as $e) {
            $interpreted_input .= $e['match'];
        }

        if ($interpreted_input !== $input) {
            trigger_error('Indenter: Did not reproduce the exact input.', E_USER_NOTICE);
        }

        $output = preg_replace('/(<(\w+)[^>]*>)\s*(<\/\2>)/', '\\1\\3', $output);

        foreach ($this->temporary_replacements_script as $i => $original) {
            $output = str_replace('<script>' . ($i + 1) . '</script>', $original, $output);
        }

        foreach ($this->temporary_replacements_inline as $i => $original) {
            $output = str_replace('???' . ($i + 1) . '???', $original, $output);
        }

        return trim($output);
    }

    /**
     * Debugging utility. Get log for the last indent operation.
     *
     * @return array
     */
    public function getLog()
    {
        return $this->log;
    }
}
