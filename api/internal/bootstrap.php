<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
function internalApiResponse(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function internalApiRun(callable $callback): never {
    try {
        $result = $callback();
        internalApiResponse(is_array($result) ? $result : ['success' => true]);
    } catch (AuthenticationException $error) {
        internalApiResponse(['success' => false, 'error' => $error->getMessage()], 401);
    } catch (AccessDeniedException $error) {
        internalApiResponse(['success' => false, 'error' => $error->getMessage()], 403);
    } catch (ConflictException $error) {
        internalApiResponse(['success' => false, 'error' => $error->getMessage()], 409);
    } catch (ValidationException $error) {
        internalApiResponse(['success' => false, 'error' => $error->getMessage()], 422);
    } catch (FileStorageException $error) {
        internalApiResponse(['success' => false, 'error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        error_log('Internal bot API failure: ' . get_class($error) . ': ' . $error->getMessage());
        internalApiResponse(['success' => false, 'error' => 'Внутренний сервис временно недоступен'], 503);
    }
}
