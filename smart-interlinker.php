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
            'content' => substr(strip_tags($page->content()), 0, 1000),
        ];

        $customFields = $this->config->get('plugins.smart-interlinker.match_fields') ?? [];
        foreach ($customFields as $field) {
            if ($page->header()->{$field} ?? null) {
                $entry['field_' . $field] = $page->header()->{$field};
            }
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

        $customFields = $this->config->get('plugins.smart-interlinker.match_fields') ?? [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pagesPath));

        foreach ($iter as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }
            $entry = $this->parsePageFile($file->getPathname(), $pagesPath, $customFields);
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

    private function parsePageFile($filePath, $pagesRoot, $customFields)
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) return null;

        $header = [];
        $body = $raw;
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $m)) {
            $header = $this->parseSimpleYaml($m[1]);
            $body = $m[2];
        }

        if (isset($header['published']) && $header['published'] === false) return null;

        $relDir = str_replace($pagesRoot, '', dirname($filePath));
        $parts = array_filter(explode('/', $relDir));
        $route = '/';
        foreach ($parts as $p) {
            $p = preg_replace('/^\d+\./', '', $p);
            $route .= $p . '/';
        }
        $route = rtrim($route, '/') ?: '/';

        $homePrefix = $this->getHomeRoute();
        if ($homePrefix !== '' && strpos($route, $homePrefix . '/') === 0) {
            $route = substr($route, strlen($homePrefix));
        } elseif ($homePrefix !== '' && $route === $homePrefix) {
            $route = '/';
        }

        $entry = [
            'url' => $route,
            'title' => $header['title'] ?? basename(dirname($filePath)),
            'content' => substr(strip_tags($body), 0, 1000),
        ];

        foreach ($customFields as $field) {
            if (!empty($header[$field])) {
                $entry['field_' . $field] = is_array($header[$field]) ? implode(' ', $header[$field]) : (string)$header[$field];
            }
        }

        return $entry;
    }

    public function taskAnalyze()
    {
        $content = $_POST['content'] ?? '';
        $currentRoute = $_POST['route'] ?? '';
        $threshold = $this->config->get('plugins.smart-interlinker.match_threshold') ?? 70;

        if (empty($this->loadIndex())) {
            $this->buildFullIndex();
        }

        $matches = $this->findInternalLinks($content, $currentRoute, $threshold);

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

    private function findInternalLinks($content, $currentRoute, $threshold)
    {
        $index = $this->loadIndex();
        $grouped = [];

        $phrases = $this->extractPhrases($content);
        $phrasesByLength = [];
        foreach ($phrases as $phrase) {
            $wordCount = substr_count(trim($phrase), ' ') + 1;
            $phrasesByLength[$wordCount][] = $phrase;
        }
        krsort($phrasesByLength);

        foreach ($phrasesByLength as $wordCount => $phraseList) {
            foreach ($phraseList as $phrase) {
                $seoBoost = min(20, ($wordCount - 1) * 10);

                foreach ($index as $route => $entry) {
                    if ($route === $currentRoute) continue;

                    $baseScore = $this->fuzzyMatch($phrase, $entry, $threshold);
                    if ($baseScore <= 0) continue;

                    $finalScore = min(100, $baseScore + $seoBoost);
                    $key = mb_strtolower($phrase);

                    if (!isset($grouped[$key])) {
                        $grouped[$key] = [
                            'phrase' => $phrase,
                            'word_count' => $wordCount,
                            'best_score' => $finalScore,
                            'targets' => [],
                        ];
                    }

                    $grouped[$key]['targets'][$route] = [
                        'url' => $route,
                        'title' => $entry['title'],
                        'score' => $finalScore,
                    ];
                    if ($finalScore > $grouped[$key]['best_score']) {
                        $grouped[$key]['best_score'] = $finalScore;
                    }
                }
            }
        }

        foreach ($grouped as &$g) {
            $g['targets'] = array_values($g['targets']);
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

    private function extractPhrases($content)
    {
        $minLength = 4;
        $stopwords = [
            'para', 'com', 'que', 'uma', 'por', 'dos', 'das', 'sobre', 'como', 'seus', 'suas', 'quando', 'onde', 'pelo', 'pela',
            'este', 'esta', 'esse', 'essa', 'isto', 'isso', 'aquele', 'aquela', 'mais', 'menos', 'muito', 'muita', 'mesmo', 'mesma',
            'the', 'and', 'for', 'with', 'this', 'that', 'from', 'have', 'been', 'were', 'will', 'would', 'could', 'should', 'their',
            'there', 'which', 'what', 'when', 'where', 'your', 'some', 'other', 'about', 'into', 'than', 'then', 'them', 'they',
        ];

        $content = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', ' ', $content);
        $content = preg_replace('/\[([^\]]*)\]\([^\)]*\)/', '$1', $content);
        $content = preg_replace('/<!--.*?-->/s', ' ', $content);
        $content = preg_replace('/```.*?```/s', ' ', $content);
        $content = preg_replace('/`[^`]*`/', ' ', $content);
        $content = preg_replace('/[#*_>~\|\[\]\(\)\{\}\"\'`]/', ' ', $content);
        $content = strip_tags($content);

        $words = preg_split('/[\s,;:.!?\/\\\\]+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter($words, fn($w) => mb_strlen($w) >= $minLength && preg_match('/^[\p{L}\p{N}-]+$/u', $w)));

        $phrases = [];
        $count = count($words);

        for ($i = 0; $i < $count; $i++) {
            $word = $words[$i];
            $lcWord = mb_strtolower($word);

            if (!in_array($lcWord, $stopwords) && mb_strlen($word) >= 5) {
                $phrases[] = $word;
            }
            if ($i + 1 < $count) {
                $bigram = $word . ' ' . $words[$i + 1];
                $lcBigram = mb_strtolower($bigram);
                if (!$this->isAllStopwords($lcBigram, $stopwords)) {
                    $phrases[] = $bigram;
                }
            }
            if ($i + 2 < $count) {
                $trigram = $word . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
                if (strlen($trigram) <= 60 && !$this->isAllStopwords(mb_strtolower($trigram), $stopwords)) {
                    $phrases[] = $trigram;
                }
            }
        }

        return array_unique($phrases);
    }

    private function isAllStopwords($phrase, $stopwords)
    {
        $words = explode(' ', $phrase);
        foreach ($words as $w) {
            if (!in_array($w, $stopwords)) return false;
        }
        return true;
    }

    private function fuzzyMatch($phrase, $entry, $threshold)
    {
        $weights = [
            'title' => 100,
            'field' => 85,
            'content' => 70,
        ];

        $maxScore = 0;

        if (!empty($entry['title']) && stripos($entry['title'], $phrase) !== false) {
            $maxScore = max($maxScore, $weights['title']);
        }

        foreach ($entry as $key => $value) {
            if (strpos($key, 'field_') === 0 && is_string($value) && stripos($value, $phrase) !== false) {
                $maxScore = max($maxScore, $weights['field']);
            }
        }

        if (!empty($entry['content']) && stripos($entry['content'], $phrase) !== false) {
            $maxScore = max($maxScore, $weights['content']);
        }

        return $maxScore >= $threshold ? $maxScore : 0;
    }

}
