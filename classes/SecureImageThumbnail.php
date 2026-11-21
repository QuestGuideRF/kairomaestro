<?php
class SecureImageThumbnail {
    private const JPEG_MIME = 'image/jpeg';

    public function deliver(array $record, bool $public = false): bool {
        $delivered = false;
        $source = $this->source($record);
        if ($source) {
            $geometry = $this->geometry($source);
            $thumbnail = $geometry
                ? $this->render($source, $geometry)
                : false;
            if ($thumbnail) {
                $encoded = $this->encode($thumbnail);
                imagedestroy($thumbnail);
                if ($encoded !== null) {
                    $this->send($encoded, $public);
                    $delivered = true;
                }
            }
            imagedestroy($source);
        }

        return $delivered;
    }

    private function source(array $record) {
        $path = (string)($record['absolute_path'] ?? '');
        $mime = (string)($record['mime_type'] ?? '');
        $source = false;
        $supported = str_starts_with($mime, 'image/')
            && function_exists('imagecreatefromstring')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled');
        if ($supported) {
            $bytes = @file_get_contents($path);
            $validBytes = is_string($bytes)
                && $bytes !== ''
                && strlen($bytes) <= UPLOAD_LIMIT_BYTES;
            if ($validBytes) {
                $source = @imagecreatefromstring($bytes);
            }
        }

        return $source;
    }

    private function geometry($source): ?array {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $geometry = null;
        if ($sourceWidth > 0 && $sourceHeight > 0) {
            $targetWidth = 360;
            $targetHeight = 240;
            $targetRatio = $targetWidth / $targetHeight;
            if ($sourceWidth / $sourceHeight > $targetRatio) {
                $cropHeight = $sourceHeight;
                $cropWidth = (int)round($sourceHeight * $targetRatio);
                $sourceX = (int)floor(($sourceWidth - $cropWidth) / 2);
                $sourceY = 0;
            } else {
                $cropWidth = $sourceWidth;
                $cropHeight = (int)round($sourceWidth / $targetRatio);
                $sourceX = 0;
                $sourceY = (int)floor(($sourceHeight - $cropHeight) / 2);
            }
            $geometry = compact(
                'targetWidth',
                'targetHeight',
                'cropWidth',
                'cropHeight',
                'sourceX',
                'sourceY'
            );
        }

        return $geometry;
    }

    private function render($source, array $geometry) {
        $thumbnail = imagecreatetruecolor(
            $geometry['targetWidth'],
            $geometry['targetHeight']
        );
        if ($thumbnail) {
            imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                $geometry['sourceX'],
                $geometry['sourceY'],
                $geometry['targetWidth'],
                $geometry['targetHeight'],
                $geometry['cropWidth'],
                $geometry['cropHeight']
            );
        }

        return $thumbnail;
    }

    private function encode($thumbnail): ?array {
        ob_start();
        if (function_exists('imagewebp')) {
            $written = imagewebp($thumbnail, null, 76);
            $mime = 'image/webp';
        } elseif (function_exists('imagejpeg')) {
            $written = imagejpeg($thumbnail, null, 82);
            $mime = self::JPEG_MIME;
        } else {
            $written = false;
            $mime = self::JPEG_MIME;
        }
        $output = ob_get_clean();

        return $written && is_string($output) && $output !== ''
            ? ['body' => $output, 'mime' => $mime]
            : null;
    }

    private function send(array $encoded, bool $public): void {
        header('Content-Type: ' . $encoded['mime']);
        header('Content-Length: ' . strlen($encoded['body']));
        header(
            'Content-Disposition: inline; '
            . 'filename="reference-thumbnail.webp"'
        );
        header('Cache-Control: ' . ($public ? 'public' : 'private') . ', max-age=86400');
        header('X-Content-Type-Options: nosniff');
        echo $encoded['body'];
    }
}
