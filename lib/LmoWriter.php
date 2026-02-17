<?php

class LmoWriter
{
    public function save($filePath, $data)
    {
        // Validate file path to prevent path traversal
        $filePath = realpath(dirname($filePath)) . '/' . basename($filePath);
        
        // Ensure directory exists and is writable
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            throw new Exception("Directory does not exist: $dir");
        }
        if (!is_writable($dir)) {
            throw new Exception("Directory is not writable: $dir");
        }

        $content = "";

        // Helper to perform simple INI writing compatible with LMO
        foreach ($data as $sectionName => $sectionContent) {
            if (is_array($sectionContent)) {
                $content .= "[$sectionName]\n";
                foreach ($sectionContent as $key => $value) {
                    // Sanitize key and value to prevent INI injection
                    $key = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
                    $value = str_replace(["\n", "\r"], '', $value);
                    $content .= "$key=$value\n";
                }
                $content .= "\n";
            } else {
                // Should not happen in standard structure
            }
        }

        // Convert back to ISO-8859-1 for compatibility with old LMO apps
        // Usually LMO expects ISO.
        $content = mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8');

        return file_put_contents($filePath, $content);
    }
}
