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
        // Always generate phrases from 1 word up. The config min_phrase_words is exposed
        // to the client as the default for the modal's "Phrase length" slider; the user
        // can drag down to 1 to discover single-word matches without rebuilding anything.
        $minWords = 1;
        $ignoredTerms = $this->normalizeTermList($this->config->get('plugins.smart-interlinker.ignored_terms') ?? []);
        $stopwords = $this->getStopwords();

        // Tokenize the source the same way titles are tokenized so that compound tokens
        // like "22.3", "RISC-V" stay intact. Then phrase lookup is a simple bounded-by-
        // space substring against the joined token stream.
        $sourceClean = $this->stripMarkdown($sourceContent);
        $sourceTokens = $this->tokenizeForPhrases(mb_strtolower($sourceClean));
        $sourceJoined = ' ' . implode(' ', $sourceTokens) . ' ';
        $currentRouteNorm = $this->normalizeRoute($currentRoute);

        // Step 1: per target, collect every matching phrase (with coverage score).
        $matchesPerTarget = []; // route => ['title'=>..., 'phrases'=> [phrase => ['word_count'=>..., 'score'=>...]]]

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

            $hits = [];
            foreach ($allPhrases as $phrase) {
                if (!$this->phraseInSource($phrase, $sourceJoined)) continue;

                $phraseWords = substr_count($phrase, ' ') + 1;
                $coverage = (int)round(($phraseWords / $titleWordCount) * 100);
                $coverage = max(0, min(100, $coverage));

                if ($coverage < $threshold) continue;

                $hits[$phrase] = ['word_count' => $phraseWords, 'score' => $coverage];
            }

            if ($hits) {
                $matchesPerTarget[$route] = ['title' => $entry['title'], 'phrases' => $hits];
            }
        }

        // Step 2: per target, drop phrases that are subsumed by a longer matching phrase
        // (e.g. "Dolor Engine" subsumes "Dolor"); but unrelated short matches like
        // "Software" and "QEMU" both survive — they suggest different anchor words.
        foreach ($matchesPerTarget as $route => &$bucket) {
            $phraseList = array_keys($bucket['phrases']);
            $kept = [];
            foreach ($phraseList as $p1) {
                $isSubsumed = false;
                foreach ($phraseList as $p2) {
                    if ($p1 === $p2) continue;
                    if ($this->phraseSubsumes($p1, $p2)) {
                        $isSubsumed = true;
                        break;
                    }
                }
                if (!$isSubsumed) $kept[$p1] = $bucket['phrases'][$p1];
            }
            $bucket['phrases'] = $kept;
        }
        unset($bucket);

        // Step 3: group by phrase. Same phrase across multiple targets shares one row.
        $grouped = [];
        foreach ($matchesPerTarget as $route => $bucket) {
            foreach ($bucket['phrases'] as $phrase => $info) {
                $key = mb_strtolower($phrase);
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'phrase' => $phrase,
                        'word_count' => $info['word_count'],
                        'best_score' => $info['score'],
                        'targets' => [],
                    ];
                }
                $grouped[$key]['targets'][] = [
                    'url' => $route,
                    'title' => $bucket['title'],
                    'score' => $info['score'],
                ];
                if ($info['score'] > $grouped[$key]['best_score']) {
                    $grouped[$key]['best_score'] = $info['score'];
                }
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

        // Cap large enough to fit all reasonable result sets. The frontend filters by
        // confidence + exact phrase length, so we don't want shorter matches to be cut
        // off by a too-tight cap.
        return array_slice(array_values($grouped), 0, 500);
    }

    private function getStopwords()
    {
        return [
            // Portuguese — articles
            'o', 'a', 'os', 'as', 'um', 'uma', 'uns', 'umas',
            // Portuguese — prepositions and contractions
            'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas',
            'ao', 'aos', 'à', 'às', 'pelo', 'pela', 'pelos', 'pelas',
            'com', 'por', 'para', 'sem', 'sob', 'sobre', 'ante', 'após',
            'até', 'contra', 'desde', 'entre', 'perante', 'segundo', 'trás',
            'num', 'numa', 'nuns', 'numas', 'dum', 'duma', 'duns', 'dumas',
            // Portuguese — conjunctions
            'e', 'ou', 'mas', 'nem', 'porém', 'contudo', 'todavia', 'entretanto',
            'logo', 'portanto', 'pois', 'porque', 'embora', 'conquanto', 'se',
            // Portuguese — pronouns/determiners
            'que', 'quem', 'qual', 'quais', 'cujo', 'cuja', 'cujos', 'cujas',
            'este', 'esta', 'estes', 'estas', 'esse', 'essa', 'esses', 'essas',
            'isto', 'isso', 'aquele', 'aquela', 'aqueles', 'aquelas', 'aquilo',
            'meu', 'minha', 'meus', 'minhas', 'teu', 'tua', 'teus', 'tuas',
            'seu', 'sua', 'seus', 'suas', 'nosso', 'nossa', 'nossos', 'nossas',
            // Portuguese — common modifiers/adverbs/etc
            'mais', 'menos', 'muito', 'muita', 'muitos', 'muitas',
            'mesmo', 'mesma', 'mesmos', 'mesmas', 'tudo', 'todo', 'toda', 'todos', 'todas',
            'também', 'já', 'ainda', 'agora', 'aqui', 'ali', 'lá', 'cá',
            'como', 'quando', 'onde', 'aonde', 'quanto', 'quanta',
            'foi', 'são', 'era', 'eram', 'estar', 'estar', 'ser', 'ter', 'haver',
            // English — articles + common short prepositions/conjunctions/pronouns
            'a', 'an', 'the', 'this', 'that', 'these', 'those',
            'i', 'me', 'my', 'mine', 'we', 'us', 'our', 'ours',
            'you', 'your', 'yours', 'he', 'she', 'it', 'its', 'his', 'her', 'hers', 'him',
            'they', 'them', 'their', 'theirs',
            'is', 'am', 'are', 'was', 'were', 'be', 'been', 'being',
            'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'shall', 'might', 'must', 'may',
            'in', 'on', 'at', 'by', 'to', 'of', 'for', 'from', 'with', 'about', 'into', 'onto', 'off', 'out',
            'over', 'under', 'up', 'down', 'as', 'so', 'or', 'and', 'but', 'nor', 'not', 'no',
            'than', 'then', 'than', 'too', 'very', 'just', 'only', 'also', 'such',
            'who', 'whom', 'whose', 'which', 'what', 'when', 'where', 'why', 'how',
            'some', 'any', 'all', 'each', 'every', 'most', 'more', 'less', 'few', 'many', 'much', 'other', 'another',
            'there', 'here', 'one', 'two',
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
        // Preserve dots, hyphens, and underscores so version numbers like "6.1.4" stay intact;
        // strip everything else (sentence punctuation, brackets, etc.) into spaces.
        $text = preg_replace('/[^\p{L}\p{N}\.\-_\s]+/u', ' ', $text);
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) return [];
        // Trim leading/trailing punctuation ("world." -> "world") but keep internal "6.1.4".
        $out = [];
        foreach ($tokens as $t) {
            $t = trim($t, ".-_");
            if ($t !== '') $out[] = $t;
        }
        return $out;
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
                // Skip 1-word phrases that contain no Unicode letter (e.g. pure version
                // numbers like "5.18" or "40" — useless as standalone link anchors).
                if ($n === 1 && !preg_match('/\p{L}/u', $slice[0])) continue;
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
        // Markdown ATX headings (# H1 ... ###### H6): strip the whole line. Headings are
        // structural and the editor's own labels — suggestions inside them are awkward.
        $content = preg_replace('/^[ \t]{0,3}#{1,6}[ \t]+.*$/m', ' ', $content);
        // Setext headings: a line of text followed by === or --- on the next line.
        $content = preg_replace('/^[ \t]*[^\n]+\n[ \t]*(={3,}|-{3,})[ \t]*$/m', ' ', $content);
        // HTML headings: <h1>..</h1> through <h6>..</h6>
        $content = preg_replace('/<h[1-6]\b[^>]*>[\s\S]*?<\/h[1-6]>/i', ' ', $content);
        // Images: drop entirely
        $content = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', ' ', $content);
        // Markdown links: drop the entire [text](url) block — text inside an existing
        // link should not be considered as a candidate for being linked again.
        $content = preg_replace('/\[[^\]]*\]\([^\)]*\)/', ' ', $content);
        // Reference-style markdown links: [text][ref]
        $content = preg_replace('/\[[^\]]*\]\[[^\]]*\]/', ' ', $content);
        // Auto-links: <https://example.com>
        $content = preg_replace('/<https?:\/\/[^>]+>/i', ' ', $content);
        // HTML anchors: <a ...>text</a>
        $content = preg_replace('/<a\b[^>]*>.*?<\/a>/is', ' ', $content);
        // Bare URLs: words inside a URL's path/query (e.g. /linux-kernel-5-18) should
        // not be considered as candidate matches.
        $content = preg_replace('/\bhttps?:\/\/[^\s<>()\[\]"\']+/i', ' ', $content);
        $content = preg_replace('/\bwww\.[^\s<>()\[\]"\']+/i', ' ', $content);
        // HTML comments
        $content = preg_replace('/<!--.*?-->/s', ' ', $content);
        // Fenced code blocks
        $content = preg_replace('/```.*?```/s', ' ', $content);
        // Inline code
        $content = preg_replace('/`[^`]*`/', ' ', $content);
        return $content;
    }

    /**
     * Check whether the phrase appears in the source as a sequence of whole tokens.
     * $sourceJoined is the source already tokenized by tokenizeForPhrases() and joined
     * with single spaces, with a leading and trailing space so first/last tokens still
     * have boundary delimiters. Looking up " phrase " then guarantees a whole-word match
     * across the entire phrase.
     */
    private function phraseInSource($phrase, $sourceJoined)
    {
        $needle = ' ' . mb_strtolower($phrase) . ' ';
        return strpos($sourceJoined, $needle) !== false;
    }

    /**
     * True when $shorter appears as a contiguous word-boundary substring of $longer.
     * Used to dedup overlapping matches per target ("Dolor" subsumed by "Dolor Engine").
     */
    private function phraseSubsumes($shorter, $longer)
    {
        $sLc = mb_strtolower($shorter);
        $lLc = mb_strtolower($longer);
        if ($sLc === $lLc) return false;
        if (mb_strlen($sLc) >= mb_strlen($lLc)) return false;
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($sLc, '/') . '(?![\p{L}\p{N}])/u';
        return preg_match($pattern, $lLc) === 1;
    }
}
