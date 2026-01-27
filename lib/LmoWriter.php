<?php

class LmoWriter
{
    public function save($filePath, $data)
    {
        $content = "";

        // Helper to perform simple INI writing compatible with LMO
        foreach ($data as $sectionName => $sectionContent) {
            if (is_array($sectionContent)) {
                $content .= "[$sectionName]\n";
                foreach ($sectionContent as $key => $value) {
                    $content .= "$key=$value\n";
                }
                $content .= "\n";
            } else {
                // Should not happen in standard structure
            }
        }

        // Convert back to ISO-8859-1 for compatibility with old LMO apps?
        // Usually LMO expects ISO.
        $content = mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8');

        return file_put_contents($filePath, $content);
    }
}
