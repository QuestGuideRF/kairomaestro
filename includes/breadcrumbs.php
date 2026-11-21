<?php
$breadcrumbItems = [];
foreach ((array)($breadcrumbs ?? []) as $breadcrumb) {
    $name = trim((string)($breadcrumb['name'] ?? ''));
    $url = trim((string)($breadcrumb['url'] ?? ''));
    if ($name !== '' && $url !== '') {
        $breadcrumbItems[] = ['name' => $name, 'url' => $url];
    }
}
if (count($breadcrumbItems) > 1) {
?>
<nav class="breadcrumbs" aria-label="Хлебные крошки">
    <div class="container">
        <ol>
            <?php foreach ($breadcrumbItems as $index => $item): ?>
                <li>
                    <?php if ($index < count($breadcrumbItems) - 1) { ?>
                        <a href="<?php echo escape($item['url']); ?>">
                            <?php echo escape($item['name']); ?>
                        </a>
                    <?php } else { ?>
                        <span aria-current="page"><?php echo escape($item['name']); ?></span>
                    <?php } ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</nav>
<?php } ?>
