<?php
final class PortfolioProjectAdmin {
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_IMAGE_BYTES = 5242880;
    private const MAX_GALLERY_UPLOADS = 12;
    private const BLOCKED_SLUGS = [
        'sadovaya-svadba-v-podmoskove',
        'minimalistichnaya-svadba-v-moskve',
        'desertnyy-stol-s-tsvetami',
        'belaya-shatrovaya-svadba',
        'tsvetochnaya-arka',
        'yarkaya-floristika-na-stole',
        'vecherniy-banket',
        'kamernaia-zona-molodyh',
        'lawn-ceremony',
    ];

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function isReady(): bool {
        return dbTableExists('portfolio_projects')
            && dbTableExists('portfolio_images');
    }

    public function projects(): array {
        if (!$this->isReady()) {
            return [];
        }
        return $this->db->query(
            'SELECT project.*,
                    (SELECT COUNT(*) FROM portfolio_images image
                     WHERE image.project_id=project.id) AS image_count
             FROM portfolio_projects project
             ORDER BY project.sort_order, project.id'
        )->fetchAll();
    }

    public function project(int $id): ?array {
        if (!$this->isReady() || $id < 1) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM portfolio_projects WHERE id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        if (!$project) {
            return null;
        }
        $project['images'] = $this->images($id);
        return $project;
    }

    public function save(
        array $input,
        array $files,
        int $adminId
    ): array {
        if (!$this->isReady()) {
            return [
                'success' => false,
                'error' => 'Таблицы портфолио отсутствуют. Обновите базу данных.',
            ];
        }

        $uploadedPaths = [];
        try {
            $data = PortfolioProjectValidator::validate($input);
            $id = max(0, (int)($input['id'] ?? 0));
            $this->assertProjectCanBeSaved($id, $data['slug']);
            $this->assertUniqueSlug($data['slug'], $id);
            $uploads = $this->storeProjectUploads($id, $files);
            $uploadedPaths = $uploads['all'];
            $this->db->beginTransaction();
            $id = $this->persistProject($id, $data, $uploads['cover']);
            $this->persistGallery($id, $data, $uploads['gallery']);
            $this->db->commit();
            $this->recordProjectSaved(
                $id,
                $data,
                count($uploads['gallery']),
                $adminId
            );
            return ['success' => true, 'id' => $id];
        } catch (Exception $exception) {
            return $this->saveFailure($exception, $uploadedPaths);
        }
    }

    private function assertProjectCanBeSaved(int $id, string $slug): void {
        if ($id > 0 && !$this->project($id)) {
            throw new EntityNotFoundException('Проект не найден.');
        }
        if (in_array($slug, self::BLOCKED_SLUGS, true)) {
            throw new ValidationException(
                'Этот slug принадлежит удалённому демонстрационному проекту.'
            );
        }
    }

    private function storeProjectUploads(int $id, array $files): array {
        $cover = $this->storeOptionalImage($files['cover'] ?? null);
        if ($id === 0 && $cover === null) {
            throw new ValidationException('Добавьте обложку проекта.');
        }
        $galleryFiles = $this->normalizedUploads($files['gallery'] ?? null);
        if (count($galleryFiles) > self::MAX_GALLERY_UPLOADS) {
            throw new ValidationException(
                'За один раз можно добавить не более 12 фотографий.'
            );
        }
        $galleryPaths = [];
        foreach ($galleryFiles as $file) {
            $path = $this->storeOptionalImage($file);
            if ($path !== null) {
                $galleryPaths[] = $path;
            }
        }
        return [
            'cover' => $cover,
            'gallery' => $galleryPaths,
            'all' => array_values(array_filter(array_merge(
                [$cover],
                $galleryPaths
            ))),
        ];
    }

    private function persistProject(
        int $id,
        array $data,
        ?string $cover
    ): int {
        if ($id > 0) {
            $this->updateProject($id, $data, $cover);
            return $id;
        }
        return $this->insertProject($data, $cover);
    }

    private function updateProject(
        int $id,
        array $data,
        ?string $cover
    ): void {
        $sql = 'UPDATE portfolio_projects
                SET slug=?,title=?,style=?,location=?,guests_count=?,
                    description=?,alt_text=?,event_date=?,is_published=?,
                    sort_order=?'
            . ($cover !== null ? ',cover_image=?' : '')
            . ' WHERE id=?';
        $params = $this->projectParams($data);
        if ($cover !== null) {
            $params[] = $cover;
        }
        $params[] = $id;
        $this->db->prepare($sql)->execute($params);
    }

    private function insertProject(array $data, ?string $cover): int {
        $stmt = $this->db->prepare(
            'INSERT INTO portfolio_projects
                (slug,title,style,location,guests_count,description,
                 cover_image,alt_text,event_date,is_published,sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $params = $this->projectParams($data);
        array_splice($params, 6, 0, [$cover]);
        $stmt->execute($params);
        return (int)$this->db->lastInsertId();
    }

    private function projectParams(array $data): array {
        return [
            $data['slug'],
            $data['title'],
            $data['style'],
            $data['location'],
            $data['guests_count'],
            $data['description'],
            $data['alt_text'],
            $data['event_date'],
            $data['is_published'],
            $data['sort_order'],
        ];
    }

    private function persistGallery(
        int $projectId,
        array $data,
        array $galleryPaths
    ): void {
        $nextSortOrder = $this->nextImageSortOrder($projectId);
        $altPrefix = $data['gallery_alt_prefix'] ?: $data['title'];
        $stmt = $this->db->prepare(
            'INSERT INTO portfolio_images
                (project_id,image_url,alt_text,sort_order)
             VALUES (?,?,?,?)'
        );
        foreach ($galleryPaths as $index => $path) {
            $stmt->execute([
                $projectId,
                $path,
                $altPrefix . ', фото ' . ($nextSortOrder + $index + 1),
                $nextSortOrder + $index,
            ]);
        }
    }

    private function recordProjectSaved(
        int $id,
        array $data,
        int $galleryCount,
        int $adminId
    ): void {
        AuditLog::record(
            'portfolio.project_saved',
            'portfolio_project',
            $id,
            null,
            [
                'slug' => $data['slug'],
                'published' => $data['is_published'] === 1,
                'gallery_added' => $galleryCount,
            ],
            [
                'user_id' => $adminId,
                'role' => 'admin',
                'result' => 'success',
            ]
        );
    }

    private function saveFailure(
        Exception $exception,
        array $uploadedPaths
    ): array {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        foreach ($uploadedPaths as $path) {
            FileStorage::deleteOwnedFile(
                $path,
                'media',
                'portfolio_upload'
            );
        }
        return [
            'success' => false,
            'error' => publicExceptionMessage(
                $exception,
                'Не удалось сохранить проект.'
            ),
        ];
    }

    public function removeImage(
        int $imageId,
        int $adminId
    ): array {
        if (!$this->isReady() || $imageId < 1) {
            return ['success' => false, 'error' => 'Изображение не найдено.'];
        }
        $stmt = $this->db->prepare(
            'SELECT id,project_id,image_url
             FROM portfolio_images
             WHERE id=?
             LIMIT 1'
        );
        $stmt->execute([$imageId]);
        $image = $stmt->fetch();
        if (!$image) {
            return ['success' => false, 'error' => 'Изображение не найдено.'];
        }
        $this->db->prepare('DELETE FROM portfolio_images WHERE id=?')
            ->execute([$imageId]);
        FileStorage::deleteOwnedFile(
            (string)$image['image_url'],
            'media',
            'portfolio_image',
            $imageId
        );
        AuditLog::record(
            'portfolio.image_removed',
            'portfolio_project',
            (int)$image['project_id'],
            null,
            ['image_id' => $imageId],
            [
                'user_id' => $adminId,
                'role' => 'admin',
                'result' => 'success',
            ]
        );
        return [
            'success' => true,
            'id' => (int)$image['project_id'],
        ];
    }

    private function images(int $projectId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM portfolio_images
             WHERE project_id=?
             ORDER BY sort_order,id'
        );
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    private function storeOptionalImage($file): ?string {
        if (!is_array($file)
            || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new FileStorageException('Не удалось получить изображение.');
        }
        $dimensions = @getimagesize((string)$file['tmp_name']);
        if (!$dimensions
            || (int)$dimensions[0] < 320
            || (int)$dimensions[1] < 320
            || (int)$dimensions[0] > 12000
            || (int)$dimensions[1] > 12000) {
            throw new FileStorageException(
                'Изображение должно быть от 320 до 12 000 пикселей по каждой стороне.'
            );
        }
        $result = FileStorage::store(
            $file,
            'media',
            self::IMAGE_MIMES,
            min(UPLOAD_LIMIT_BYTES, self::MAX_IMAGE_BYTES)
        );
        if (empty($result['success'])) {
            throw new FileStorageException(
                (string)($result['error'] ?? 'Не удалось сохранить изображение.')
            );
        }
        return (string)$result['file_path'];
    }

    private function normalizedUploads($files): array {
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            return [];
        }
        $uploads = [];
        foreach ($files['name'] as $index => $name) {
            $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $uploads[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $error,
                'size' => $files['size'][$index] ?? 0,
            ];
        }
        return $uploads;
    }

    private function nextImageSortOrder(int $projectId): int {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order),-1)+1
             FROM portfolio_images
             WHERE project_id=?'
        );
        $stmt->execute([$projectId]);
        return (int)$stmt->fetchColumn();
    }

    private function assertUniqueSlug(string $slug, int $currentId): void {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM portfolio_projects
             WHERE slug=? AND id<>?
             LIMIT 1'
        );
        $stmt->execute([$slug, $currentId]);
        if ($stmt->fetchColumn()) {
            throw new ValidationException(
                'Проект с таким slug уже существует.'
            );
        }
    }
}
