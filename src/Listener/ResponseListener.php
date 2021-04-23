<?php declare(strict_types=1);

namespace Frosh\HtmlMinify\Listener;

use Composer\Autoload\ClassLoader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use JSMin\JSMin;

class ResponseListener
{
    private $javascriptPlaceholder = '##SCRIPTPOSITION##';
    private $spacePlaceholder = '##SPACE##';

    /**
     * @param ResponseEvent $event
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $start = microtime(true);

        if (!$event->isMasterRequest()) {
            return;
        }

        $response = $event->getResponse();

        if ($response instanceof BinaryFileResponse ||
            $response instanceof StreamedResponse) {
            return;
        }

        if (strpos($response->headers->get('Content-Type', ''), 'text/html') === false) {
            return;
        }

        $file = __DIR__.'/../../vendor/autoload.php';
        $classLoader = require_once $file;

        if ($classLoader instanceof ClassLoader) {
            $classLoader->unregister();
            $classLoader->register(false);
        }

        $content = $response->getContent();
        $lengthInitialContent = mb_strlen($content, 'utf8');

        $this->minifySourceTypes($content);

        $javascripts = $this->extractCombinedInlineScripts($content);

        $this->minifyJavascript($javascripts);
        $this->minifyHtml($content);

        $content = str_replace($this->javascriptPlaceholder, '<script>' . $javascripts . '</script>', $content);

        $lengthContent = mb_strlen($content, 'utf8');
        $savedData = round(100 - 100 / ($lengthInitialContent / $lengthContent), 2);
        $timeTook = (int) ((microtime(true) - $start) * 1000);

        $response->headers->add(['X-Html-Compressor' => time() . ': ' . $savedData . '% ' . $timeTook. 'ms']);

        $response->setContent($content);
    }

    private function minifyJavascript(string &$content): void {
        $jsMin = new JSMin($content);
        $content = $jsMin->min();
    }

    private function minifyHtml(string &$content): void {
        $search = [
            '/(\n|^)(\x20+|\t)/',
            '/(\n|^)\/\/(.*?)(\n|$)/',
            '/\n/',
            '/\<\!--.*?-->/',
            '/(\x20+|\t)/', # Delete multispace (Without \n)
            '/span\>\s+/', # keep whitespace after span tags
            '/\s+\<span/', # keep whitespace before span tags
            '/button\>\s+/', # keep whitespace after span tags
            '/\s+\<button/', # keep whitespace before span tags
            '/\>\s+\</', # strip whitespaces between tags
            '/(\"|\')\s+\>/', # strip whitespaces between quotation ("') and end tags
            '/=\s+(\"|\')/', # strip whitespaces between = "'
            '/' . $this->spacePlaceholder . '/', # replace the spacePlaceholder at the end
        ];

        $replace = [
            "\n",
            "\n",
            ' ',
            '',
            ' ',
            'span>' . $this->spacePlaceholder,
            $this->spacePlaceholder . '<span',
            'button>' . $this->spacePlaceholder,
            $this->spacePlaceholder . '<button',
            '><',
            '$1>',
            '=$1',
            ' ',
        ];

        $content = trim(preg_replace($search, $replace, $content));
    }

    private function extractCombinedInlineScripts(string &$content): string
    {
        $scriptContents = '';
        $index = 0;
        $placeholder = $this->javascriptPlaceholder;
        if (strpos($content, '</script>') !== false) {
            $content = preg_replace_callback('#<script>(.*?)<\/script>#s', static function ($matches) use (&$scriptContents, &$index, $placeholder) {
                $index++;
                $scriptContents .= $matches[1] . PHP_EOL;
                return $index === 1 ? $placeholder : '';
            }, $content);
        }

        return $scriptContents;
    }

    private function minifySourceTypes(&$content): void
    {
        $search = [
            '/ type=["\']text\/javascript["\']/',
            '/ type=["\']text\/css["\']/',
        ];
        $replace = '';
        $content = preg_replace($search, $replace, $content);
    }
}
