<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use RocketThemes\Toolbox\Event\Event;

class SmartInterlinkerPlugin extends Plugin
{
    protected $indexFile;
    protected $cachePath;

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onPageSave' => ['onPageSave', 0],
            'onPageDelete' => ['onPageDelete', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onTask.smart-interlinker.analyze' => ['taskAnalyze', 0],
            'onTask.smart-interlinker.rebuild' => ['taskRebuildIndex', 0],
        ];
    }

    public function onPluginsInitialized()
    {
        $this->cachePath = $this->grav['locator']->findResource('plugin://smart-interlinker/cache', true, true);
        $this->indexFile = $this->cachePath . '/interlinks-index.json';
    }

    public function onPageSave(Event $e)
    {
        $page = $e['page'];
        if ($page->published()) {
            $this->updateIndexForPage($page);
        } else {
            $this->removeFromIndex($page->route());
        }
    }

    public function onPageDelete(Event $e)
    {
        $page = $e['page'];
        $this->removeFromIndex($page->route());
    }

    public function onTwigSiteVariables()
    {
        if ($this->isAdmin() && isset($this->grav['admin']) && $this->grav['admin']->location === 'pages') {
            $config = $this->config->get('plugins.smart-interlinker', []);
            $clientConfig = [
                'context_length' => (int)($config['context_length'] ?? 80),
                'match_threshold' => (int)($config['match_threshold'] ?? 70),
            ];
            $this->grav['assets']->addInlineJs('window.SmartInterlinkerConfig = ' . json_encode($clientConfig) . ';');
            $this->grav['assets']->addJs('plugin://smart-interlinker/assets/smart-interlinker.js');
            $this->grav['assets']->addCss('plugin://smart-interlinker/assets/smart-interlinker.css');
        }
    }

    private function updateIndexForPage($page)
    {
        $index = $this->loadIndex();

        $entry = [
            'url' => $page->route(),
            'title' => $page->title(),
        ];

        $keywordField = $this->config->get('plugins.smart-interlinker.keyword_field') ?? '';
        if ($keywordField && ($page->header()->{$keywordField} ?? null)) {
            $kw = $page->header()->{$keywordField};
            $entry['keyword'] = is_array($kw) ? implode(' ', $kw) : (string)$kw;
        }

        $index[$page->route()] = $entry;
        $this->saveIndex($index);
    }

    private function removeFromIndex($route)
    {
        $index = $this->loadIndex();
        unset($index[$route]);
        $this->saveIndex($index);
    }

    private function loadIndex()
    {
        if (file_exists($this->indexFile)) {
            return json_decode(file_get_contents($this->indexFile), true) ?? [];
        }
        return [];
    }

    private function saveIndex($index)
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = json_encode(['__error' => 'encode failed: ' . json_last_error_msg()]);
        }
        file_put_contents($this->indexFile, $json);
    }

    public function buildFullIndex()
    {
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');

        $index = [];
        $pagesPath = null;
        try {
            $pagesPath = $this->grav['locator']->findResource('page://', true);
        } catch (\Exception $e) {}

        if (!$pagesPath || !is_dir($pagesPath)) {
            $pagesPath = (defined('GRAV_ROOT') ? GRAV_ROOT : dirname(__DIR__, 3)) . '/user/pages';
        }

        if (!is_dir($pagesPath)) {
            $this->saveIndex([]);
            return;
        }

        $keywordField = $this->config->get('plugins.smart-interlinker.keyword_field') ?? '';
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pagesPath));

        foreach ($iter as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }
            $entry = $this->parsePageFile($file->getPathname(), $pagesPath, $keywordField);
            if ($entry && !empty($entry['url'])) {
                $index[$entry['url']] = $entry;
            }
        }

        $this->saveIndex($index);
    }

    private function parseSimpleYaml($yaml)
    {
        $result = [];
        $lines = explode("\n", $yaml);
        $currentKey = null;
        $currentList = null;

        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9_-]+):\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $val = trim($m[2]);
                if ($val === '') {
                    $currentKey = $key;
                    $currentList = [];
                    $result[$key] = $currentList;
                } else {
                    $val = trim($val, "'\"");
                    $result[$key] = $val;
                    $currentKey = null;
                }
            } elseif ($currentKey && preg_match('/^\s*-\s*(.+)$/', $line, $m)) {
                $currentList[] = trim($m[1], "'\"");
                $result[$currentKey] = $currentList;
            }
        }
        return $result;
    }

    private function getHomeRoute()
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $hide = $this->grav['config']->get('system.home.hide_in_urls', false);
        $alias = $this->grav['config']->get('system.home.alias', '/home');
        $cached = $hide ? rtrim($alias, '/') : '';
        return $cached;
    }

    private function normalizeRoute($route)
    {
        if (!$route) return '/';
        if ($route[0] !== '/') $route = '/' . $route;
        $homePrefix = $this->getHomeRoute();
        if ($homePrefix !== '' && strpos($route, $homePrefix . '/') === 0) {
            $route = substr($route, strlen($homePrefix));
        } elseif ($homePrefix !== '' && $route === $homePrefix) {
            $route = '/';
        }
        return rtrim($route, '/') ?: '/';
    }

    private function parsePageFile($filePath, $pagesRoot, $keywordField)
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) return null;

        $header = [];
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) {
            $header = $this->parseSimpleYaml($m[1]);
        }

        if (isset($header['published']) && $header['published'] === false) return null;

        $relDir = str_replace($pagesRoot, '', dirname($filePath));
        $parts = array_filter(explode('/', $relDir));
        $route = '/';
        foreach ($parts as $p) {
            $p = preg_replace('/^\d+\./', '', $p);
            $route .= $p . '/';
        }
        $route = $this->normalizeRoute($route);

        $entry = [
            'url' => $route,
            'title' => $header['title'] ?? basename(dirname($filePath)),
        ];

        if ($keywordField && !empty($header[$keywordField])) {
            $kw = $header[$keywordField];
            $entry['keyword'] = is_array($kw) ? implode(' ', $kw) : (string)$kw;
        }

        return $entry;
    }

    public function taskAnalyze()
    {
        $content = $_POST['content'] ?? '';
        $currentRoute = $_POST['route'] ?? '';

        if (empty($this->loadIndex())) {
            $this->buildFullIndex();
        }

        $matches = $this->findInternalLinks($content, $currentRoute);

        header('Content-Type: application/json');
        echo json_encode(['matches' => $matches, 'index_size' => count($this->loadIndex())]);
        exit;
    }

    public function taskRebuildIndex()
    {
        if (!$this->grav['admin']->authorize()) {
            http_response_code(403);
            exit;
        }

        $this->buildFullIndex();

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Index rebuilt']);
        exit;
    }

    private function findInternalLinks($sourceContent, $currentRoute)
    {
        $index = $this->loadIndex();
        $threshold = (int)($this->config->get('plugins.smart-interlinker.match_threshold') ?? 70);
        $minWords = max(1, (int)($this->config->get('plugins.smart-interlinker.min_phrase_words') ?? 2));
        $ignoredTerms = $this->normalizeTermList($this->config->get('plugins.smart-interlinker.ignored_terms') ?? []);
        $stopwords = $this->getStopwords();

        $sourceLc = mb_strtolower($this->stripMarkdown($sourceContent));
        $currentRouteNorm = $this->normalizeRoute($currentRoute);

        // Step 1: per target, find the single best matching phrase
        $bestPerTarget = []; // route => ['phrase'=>..., 'word_count'=>..., 'score'=>..., 'title'=>...]

        foreach ($index as $route => $entry) {
            if ($this->normalizeRoute($route) === $currentRouteNorm) continue;
            if (empty($entry['title'])) continue;

            $titleTokens = $this->tokenizeForPhrases($entry['title']);
            $titleWordCount = max(1, count($titleTokens));

            $phraseSources = [$entry['title']];
            if (!empty($entry['keyword'])) {
                $phraseSources[] = $entry['keyword'];
            }

            $allPhrases = [];
            foreach ($phraseSources as $src) {
                foreach ($this->extractTitlePhrases($src, $minWords, $stopwords, $ignoredTerms) as $p) {
                    $allPhrases[mb_strtolower($p)] = $p;
                }
            }

            foreach ($allPhrases as $phrase) {
                if (!$this->phraseInSource($phrase, $sourceLc)) continue;

                $phraseWords = substr_count($phrase, ' ') + 1;
                $coverage = (int)round(($phraseWords / $titleWordCount) * 100);
                $coverage = max(0, min(100, $coverage));

                if ($coverage < $threshold) continue;

                $candidate = [
                    'phrase' => $phrase,
                    'word_count' => $phraseWords,
                    'score' => $coverage,
                    'title' => $entry['title'],
                ];

                if (!isset($bestPerTarget[$route])) {
                    $bestPerTarget[$route] = $candidate;
                } else {
                    $cur = $bestPerTarget[$route];
                    if ($candidate['word_count'] > $cur['word_count']
                        || ($candidate['word_count'] === $cur['word_count'] && $candidate['score'] > $cur['score'])) {
                        $bestPerTarget[$route] = $candidate;
                    }
                }
            }
        }

        // Step 2: group by phrase. If multiple targets share the same best phrase,
        // they appear as alternatives in the same row.
        $grouped = [];
        foreach ($bestPerTarget as $route => $best) {
            $key = mb_strtolower($best['phrase']);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'phrase' => $best['phrase'],
                    'word_count' => $best['word_count'],
                    'best_score' => $best['score'],
                    'targets' => [],
                ];
            }
            $grouped[$key]['targets'][] = [
                'url' => $route,
                'title' => $best['title'],
                'score' => $best['score'],
            ];
            if ($best['score'] > $grouped[$key]['best_score']) {
                $grouped[$key]['best_score'] = $best['score'];
            }
        }

        foreach ($grouped as &$g) {
            usort($g['targets'], fn($a, $b) => $b['score'] <=> $a['score']);
        }
        unset($g);

        usort($grouped, function ($a, $b) {
            if ($a['word_count'] !== $b['word_count']) {
                return $b['word_count'] <=> $a['word_count'];
            }
            return $b['best_score'] <=> $a['best_score'];
        });

        return array_slice(array_values($grouped), 0, 30);
    }

    private function getStopwords()
    {
        return [
            'para', 'com', 'que', 'uma', 'por', 'dos', 'das', 'sobre', 'como', 'seus', 'suas', 'quando', 'onde', 'pelo', 'pela',
            'este', 'esta', 'esse', 'essa', 'isto', 'isso', 'aquele', 'aquela', 'mais', 'menos', 'muito', 'muita', 'mesmo', 'mesma',
            'the', 'and', 'for', 'with', 'this', 'that', 'from', 'have', 'been', 'were', 'will', 'would', 'could', 'should', 'their',
            'there', 'which', 'what', 'when', 'where', 'your', 'some', 'other', 'about', 'into', 'than', 'then', 'them', 'they',
        ];
    }

    private function normalizeTermList($terms)
    {
        if (is_string($terms)) {
            $terms = preg_split('/[,\s]+/', $terms, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($terms)) return [];
        $out = [];
        foreach ($terms as $t) {
            $t = trim((string)$t);
            if ($t !== '') $out[] = mb_strtolower($t);
        }
        return $out;
    }

    private function tokenizeForPhrases($text)
    {
        $text = preg_replace('/[^\p{L}\p{N}\-\s]+/u', ' ', $text);
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $tokens ?: [];
    }

    private function extractTitlePhrases($title, $minWords, $stopwords, $ignoredTerms)
    {
        $tokens = $this->tokenizeForPhrases($title);
        $count = count($tokens);
        if ($count < $minWords) return [];

        $maxN = min(6, $count);
        $phrases = [];

        for ($n = $maxN; $n >= $minWords; $n--) {
            for ($i = 0; $i + $n <= $count; $i++) {
                $slice = array_slice($tokens, $i, $n);
                if ($this->isAllFiltered($slice, $stopwords, $ignoredTerms)) continue;
                $phrases[] = implode(' ', $slice);
            }
        }

        return array_values(array_unique($phrases));
    }

    private function isAllFiltered($words, $stopwords, $ignoredTerms)
    {
        foreach ($words as $w) {
            $lc = mb_strtolower($w);
            if (!in_array($lc, $stopwords) && !in_array($lc, $ignoredTerms)) {
                return false;
            }
        }
        return true;
    }

    private function stripMarkdown($content)
    {
        $content = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', ' ', $content);
        $content = preg_replace('/\[([^\]]*)\]\([^\)]*\)/', '$1', $content);
        $content = preg_replace('/<!--.*?-->/s', ' ', $content);
        $content = preg_replace('/```.*?```/s', ' ', $content);
        $content = preg_replace('/`[^`]*`/', ' ', $content);
        return $content;
    }

    private function phraseInSource($phrase, $sourceLc)
    {
        $phraseLc = mb_strtolower($phrase);
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($phraseLc, '/') . '(?![\p{L}\p{N}])/u';
        return preg_match($pattern, $sourceLc) === 1;
    }
}
