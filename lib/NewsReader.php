<?php

class NewsReader
{
    private $newsDir;

    public function __construct($newsDir)
    {
        $this->newsDir = rtrim($newsDir, '/\\');
    }

    /**
     * Get latest news items
     */
    public function getLatest($limit = 10, $offset = 0)
    {
        $files = $this->getNewsFiles();
        // Sort by ID descending (files are news.ID.php)
        krsort($files, SORT_NUMERIC);

        $slice = array_slice($files, $offset, $limit, true);
        $results = [];

        foreach ($slice as $id => $path) {
            $data = $this->parseFile($path, $id);
            if ($data) {
                $results[] = $data;
            }
        }

        return $results;
    }

    /**
     * Search news for keywords (limited scope for performance)
     */
    public function search($query, $limit = 10)
    {
        $files = $this->getNewsFiles();
        krsort($files, SORT_NUMERIC); // Search newest first

        // Safety limit: only search last 500 files to prevent timeout
        // Increase this if performance allows
        $searchLimit = 500;
        $count = 0;
        $results = [];
        $query = mb_strtolower($query, 'UTF-8');

        foreach ($files as $id => $path) {
            if ($count >= $searchLimit)
                break;
            $count++;

            $data = $this->parseFile($path, $id);
            if (!$data)
                continue;

            // Search in title and content
            $haystack = mb_strtolower($data['title'] . ' ' . $data['content'], 'UTF-8');
            if (strpos($haystack, $query) !== false) {
                $results[] = $data;
                if (count($results) >= $limit)
                    break;
            }
        }

        return $results;
    }

    private function getNewsFiles()
    {
        $files = [];
        if (!is_dir($this->newsDir))
            return [];

        // Validate directory path to prevent traversal
        $realDir = realpath($this->newsDir);
        if ($realDir === false) {
            return [];
        }

        $dir = opendir($this->newsDir);
        while (($file = readdir($dir)) !== false) {
            if (preg_match('/^news\.(\d+)\.php$/', $file, $matches)) {
                $fullPath = $this->newsDir . DIRECTORY_SEPARATOR . $file;
                // Ensure the file is within our news directory
                if (strpos(realpath($fullPath), $realDir) === 0) {
                    $files[(int) $matches[1]] = $fullPath;
                }
            }
        }
        closedir($dir);
        return $files;
    }

    private function parseFile($path, $id)
    {
        if (!file_exists($path))
            return null;

        $content = file_get_contents($path);
        // Split by the separator used in FusionNews/CuteNews
        $parts = explode("|<|", $content);

        // Format is roughly:
        // 0: Content (HTML)
        // 1: Author
        // 2: Title
        // 3: Email?
        // 4: ?
        // 5: Timestamp
        // 6: ?

        if (count($parts) < 6)
            return null;

        $timestamp = (int) $parts[5];
        $title = $this->fixEncoding($parts[2]);
        $author = $this->fixEncoding($parts[1]);
        $body = $this->fixEncoding($parts[0]);

        // Cleanup body
        // Often starts with tags or breaks
        $body = strip_tags($body, '<br><b><i><strong><em><img><a>');

        return [
            'id' => $id,
            'date' => date('d.m.Y H:i', $timestamp),
            'timestamp' => $timestamp,
            'title' => $title ?: 'Ohne Titel',
            'author' => $author,
            'content' => $body
        ];
    }

    private function fixEncoding($str)
    {
        // News files are likely ISO-8859-1 or Latin1, convert to UTF-8
        // Check if already UTF-8
        if (mb_detect_encoding($str, 'UTF-8, ISO-8859-1', true) === 'UTF-8') {
            return $str;
        }
        return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }
}
