<?php
$structuredData = PublicStructuredData::graph((array)($pageSchemas ?? []));
$structuredDataJson = json_encode(
    $structuredData,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
if ($structuredDataJson !== false && !empty($structuredData['@graph'])) {
?>
<script type="application/ld+json"><?php echo $structuredDataJson; ?></script>
<?php } ?>
