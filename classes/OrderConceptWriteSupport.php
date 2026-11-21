<?php
trait OrderConceptWriteSupport {
    private function fileCount($files) {
        $names = $files['name'] ?? [];
        if (!is_array($names)) {
            return trim((string)$names) === '' ? 0 : 1;
        }
        return count(array_filter($names, static fn($name) => trim((string)$name) !== ''));
    }
    private function normalizeFiles($files) {
        if (!$files || empty($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return [$files];
        }
        $result = [];
        foreach ($files['name'] as $index => $name) {
            if ($name === '') {
                continue;
            }
            $result[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }
        return array_slice($result, 0, 12);
    }
    private function normalizeConceptInput($orderId, $userId, $data, $files) {
        $title = trim((string)($data['title'] ?? 'Концепция оформления'));
        $order = (new Order())->getById($orderId);
        if (!(new Order())->canMutate($order, (int)$userId, currentRole())) {
            throw new AccessDeniedException('Заказ недоступен для изменения');
        }
        $fields = [
            'description' => [trim((string)($data['description'] ?? '')), 50000],
            'style_name' => [trim((string)($data['style_name'] ?? '')), 190],
            'color_palette' => [trim((string)($data['color_palette'] ?? '')), 500],
            'composition_notes' => [trim((string)($data['composition_notes'] ?? '')), 50000],
        ];
        if ($title === '' || $this->textLength($title) > 190) {
            throw new ValidationException('Проверьте название концепции');
        }
        foreach ($fields as [$value, $limit]) {
            if ($this->textLength($value) > $limit) {
                throw new ValidationException('Одно из полей концепции слишком длинное');
            }
        }
        if ($this->fileCount($files) > 12) {
            throw new ValidationException('Можно приложить не более 12 файлов');
        }
        $kind = in_array(($data['file_kind'] ?? ''), ['moodboard', 'reference', 'sketch', 'photo', 'scheme', 'composition'], true)
            ? $data['file_kind']
            : 'reference';
        return ['title' => $title, 'fields' => $fields, 'file_kind' => $kind];
    }
    private function createConceptVersion($orderId, $userId, $data, $files) {
        $storedPaths = [];
        $versionId = null;
        try {
            $result = $this->persistConceptVersion(
                (int)$orderId,
                (int)$userId,
                $this->normalizeConceptInput($orderId, $userId, $data, $files),
                $files,
                $storedPaths,
                $versionId
            );
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            foreach ($storedPaths as $path) {
                FileStorage::deleteOwnedFile($path, 'concepts', 'concept_version', $versionId);
            }
            $result = ['success' => false, 'error' => publicExceptionMessage($error, 'Не удалось сохранить концепцию')];
        }
        return $result;
    }
    private function persistConceptVersion($orderId, $userId, $input, $files, &$storedPaths, &$versionId) {
        $this->db->beginTransaction();
        $context = $this->conceptVersionContext($orderId, $userId, $input['title']);
        $conceptId = $context['concept_id'];
        $versionNo = $context['version_no'];
        $fields = $input['fields'];
        $stmt = $this->db->prepare("INSERT INTO concept_versions (concept_id,version_no,description,style_name,color_palette,composition_notes,status,created_by) VALUES (?,?,?,?,?,?,'draft',?)");
        $stmt->execute([
            $conceptId,
            $versionNo,
            $fields['description'][0] ?: null,
            $fields['style_name'][0] ?: null,
            $fields['color_palette'][0] ?: null,
            $fields['composition_notes'][0] ?: null,
            $userId,
        ]);
        $versionId = (int)$this->db->lastInsertId();
        $normalizedFiles = $this->storeConceptFiles($versionId, $input['file_kind'], $files, $storedPaths);
        $this->db->prepare('UPDATE order_concepts SET title=?,current_version_id=? WHERE id=?')
            ->execute([$input['title'], $versionId, $conceptId]);
        $this->db->commit();
        AuditLog::record('concept.version_created', 'concept_version', $versionId, null, [
            'concept_id' => $conceptId,
            'version_no' => $versionNo,
            'files_count' => count($normalizedFiles),
        ], [
            'order_id' => $orderId,
            'user_id' => $userId,
        ]);
        return [
            'success' => true,
            'concept_id' => $conceptId,
            'version_id' => $versionId,
            'version_no' => $versionNo,
        ];
    }
    private function conceptVersionContext($orderId, $userId, $title) {
        $stmt = $this->db->prepare('SELECT * FROM order_concepts WHERE order_id=? ORDER BY id LIMIT 1 FOR UPDATE');
        $stmt->execute([$orderId]);
        $concept = $stmt->fetch();
        if (!$concept) {
            $stmt = $this->db->prepare('INSERT INTO order_concepts (order_id,title,created_by) VALUES (?,?,?)');
            $stmt->execute([$orderId, $title, $userId]);
            $context = ['concept_id' => (int)$this->db->lastInsertId(), 'version_no' => 1];
        } else {
            $conceptId = (int)$concept['id'];
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM concept_versions WHERE concept_id=?');
            $stmt->execute([$conceptId]);
            if ($concept['current_version_id']) {
                $this->db->prepare("UPDATE concept_versions SET status='replaced' WHERE id=? AND status<>'replaced'")
                    ->execute([(int)$concept['current_version_id']]);
            }
            $context = ['concept_id' => $conceptId, 'version_no' => (int)$stmt->fetchColumn()];
        }
        return $context;
    }
    private function storeConceptFiles($versionId, $fileKind, $files, &$storedPaths) {
        $normalized = $this->normalizeFiles($files);
        $stmt = $this->db->prepare('INSERT INTO concept_files (version_id,file_kind,file_path,file_name,mime_type,file_size,sort_order) VALUES (?,?,?,?,?,?,?)');
        foreach ($normalized as $position => $file) {
            $upload = FileStorage::store($file, 'concepts', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
            if (!$upload['success']) {
                throw new FileStorageException($upload['error']);
            }
            $storedPaths[] = $upload['file_path'];
            $stmt->execute([
                $versionId,
                $fileKind,
                $upload['file_path'],
                $upload['file_name'],
                $upload['mime_type'],
                $upload['file_size'],
                $position,
            ]);
        }
        return $normalized;
    }
}
