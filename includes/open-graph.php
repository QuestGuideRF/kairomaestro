<?php
$openGraphTitle = trim((string)($pageTitle ?? SITE_NAME));
$openGraphDescription = trim((string)($pageDescription ?? SITE_DESCRIPTION));
$openGraphUrl = trim((string)($pageCanonical ?? appUrl('/')));
$openGraphImage = trim((string)(
    $pageImage ?? appUrl('/assets/images/hero/hero-wedding-01.jpg')
));
$openGraphImageAlt = trim((string)(
    $pageImageAlt ?? 'Свадебное оформление от агентства ' . SITE_NAME
));
$openGraphType = trim((string)($pageOpenGraphType ?? 'website'));
$openGraphImageWidth = (int)($pageImageWidth ?? 1000);
$openGraphImageHeight = (int)($pageImageHeight ?? 666);

if (!preg_match('#^https?://#i', $openGraphImage)) {
    $openGraphImage = appUrl($openGraphImage);
}
?>
<meta property="og:locale" content="ru_RU">
<meta property="og:type" content="<?php echo escape($openGraphType); ?>">
<meta property="og:site_name" content="<?php echo escape(SITE_NAME); ?>">
<meta property="og:title" content="<?php echo escape($openGraphTitle); ?>">
<meta property="og:description" content="<?php echo escape($openGraphDescription); ?>">
<meta property="og:url" content="<?php echo escape($openGraphUrl); ?>">
<meta property="og:image" content="<?php echo escape($openGraphImage); ?>">
<meta property="og:image:width" content="<?php echo $openGraphImageWidth; ?>">
<meta property="og:image:height" content="<?php echo $openGraphImageHeight; ?>">
<meta property="og:image:alt" content="<?php echo escape($openGraphImageAlt); ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo escape($openGraphTitle); ?>">
<meta name="twitter:description" content="<?php echo escape($openGraphDescription); ?>">
<meta name="twitter:image" content="<?php echo escape($openGraphImage); ?>">
<meta name="twitter:image:alt" content="<?php echo escape($openGraphImageAlt); ?>">
