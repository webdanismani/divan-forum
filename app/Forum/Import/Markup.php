<?php

namespace App\Forum\Import;

/** Turns the BBCode dialects of other forum packages into the tags this forum understands. */
class Markup
{
    /** Tags without an equivalent here: the tag goes, the text inside stays. */
    protected const DROP = 'font|left|justify|indent|align|highlight|sub|sup|table|tr|td|th|tbody|thead|plain|noparse|heading|h1|h2|h3|h4|float|glow|shadow|move|pre|ltr|rtl|me|abbr|acronym|black|blue|green|red|white|flash|time|nobbc|user|usergroup|thread|post|email|anchor|attachment';

    public static function convert(string $text, bool $html = false): string
    {
        $text = Importer::text($text);

        if ($html) {
            // SMF stores line breaks and special characters as HTML.
            $text = html_entity_decode(preg_replace('/<br\s*\/?>/i', "\n", $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Tag names in lower case, so the rules below only need one spelling.
        $text = preg_replace_callback('/\[(\/?)([a-z][a-z0-9]*|\*)(?=[=\s\]])/i', fn ($m) => '['.$m[1].strtolower($m[2]), $text);

        // Quotes: [quote="name, post: 1, member: 2"], [quote=name;123], [quote='name' pid='1' dateline='2'], [quote author=name link=... date=...]
        $text = preg_replace_callback('/\[quote(?:=|\s+author=|\s+)([^\]]*)\]/', function ($m) {
            $name = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $name = preg_replace('/\s+(?:link|date|pid|dateline)=.*$/s', '', $name);
            $name = preg_replace('/\s*[;,]\s*(?:post:\s*)?\d+.*$/s', '', $name);
            $name = trim(str_replace(['[', ']', '"', "'"], '', $name));

            return $name === '' ? '[quote]' : '[quote='.$name.']';
        }, $text);

        // Files are not carried over; a leftover [attach] would point at an unrelated file here.
        $text = preg_replace('/\[attach[^\]]*\].*?\[\/attach\]/is', '', $text);
        $text = preg_replace('/\[attachment=[^\]]*\](?:.*?\[\/attachment\])?/is', '', $text);

        // Videos become plain links, which this forum turns into players again.
        $text = preg_replace('/\[media=youtube\]([\w-]{6,20})(?::\d+)?\[\/media\]/i', "\nhttps://www.youtube.com/watch?v=$1\n", $text);
        $text = preg_replace('/\[video=youtube;([\w-]{6,20})\].*?\[\/video\]/is', "\nhttps://www.youtube.com/watch?v=$1\n", $text);
        $text = preg_replace('/\[youtube\]([\w-]{6,20})\[\/youtube\]/i', "\nhttps://www.youtube.com/watch?v=$1\n", $text);
        $text = preg_replace('/\[media=vimeo\](\d+)\[\/media\]/i', "\nhttps://vimeo.com/$1\n", $text);
        $text = preg_replace('/\[(?:media|video)(?:=[^\]]*)?\](.*?)\[\/(?:media|video)\]/is', '$1', $text);

        $text = preg_replace('/\[url=(["\'])(.*?)\1\]/i', '[url=$2]', $text);
        $text = preg_replace('/\[iurl(=[^\]]*)?\]/', '[url$1]', $text);
        $text = str_replace('[/iurl]', '[/url]', $text);
        $text = preg_replace('/\[img[=\s][^\]]*\]/', '[img]', $text);
        $text = preg_replace('/\[(\/?)(?:strike|del)\]/', '[$1s]', $text);
        $text = preg_replace('/\[(\/?)(?:php|html|sql|tt)\]/', '[$1code]', $text);
        $text = preg_replace('/\[code=[^\]]*\]/', '[code]', $text);
        $text = preg_replace('/\[(\/?)(?:ispoiler|hide)\]/', '[$1spoiler]', $text);
        $text = preg_replace('/\[spoiler=[^\]]*\]/', '[spoiler]', $text);
        $text = preg_replace('/\[list=[^\]1][^\]]*\]/', '[list=1]', $text);
        $text = preg_replace('/\[list\s+type=decimal\]/', '[list=1]', $text);
        $text = preg_replace('/\[list\s+[^\]]*\]/', '[list]', $text);
        $text = preg_replace('/\[li\]/', '[*]', $text);
        $text = str_replace(['[/li]', '[hr]', '[hr /]', '[br]', '[br /]'], ['', "\n", "\n", "\n", "\n"], $text);
        $text = preg_replace('/\[\/\*(?::m)?\]/', '', $text);

        // Sizes: this forum knows 1-7. Pixel and point sizes lose the tag, not the text.
        $text = preg_replace_callback('/\[size=([^\]]*)\](.*?)\[\/size\]/is', fn ($m) => preg_match('/^[1-7]$/', trim($m[1], '"\' ')) ? '[size='.trim($m[1], '"\' ').']'.$m[2].'[/size]' : $m[2], $text);
        $text = preg_replace('/\[color=(["\'])(.*?)\1\]/', '[color=$2]', $text);

        $text = preg_replace('/\[\/?(?:'.self::DROP.')(?:[=\s][^\]]*)?\]/', '', $text);

        return trim(preg_replace("/\n{4,}/", "\n\n\n", str_replace(["\r\n", "\r"], "\n", $text)));
    }
}
