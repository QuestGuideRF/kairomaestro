<?php
final class PublicStructuredData
{
    public static function graph(array $nodes): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => array_values(array_filter(
                $nodes,
                static fn($node): bool => is_array($node) && $node !== []
            )),
        ];
    }

    public static function breadcrumbNode(array $breadcrumbs): array
    {
        $items = [];
        foreach ($breadcrumbs as $breadcrumb) {
            $name = trim((string)($breadcrumb['name'] ?? ''));
            $url = trim((string)($breadcrumb['url'] ?? ''));
            if ($name !== '' && $url !== '') {
                $items[] = ['name' => $name, 'url' => $url];
            }
        }
        if (count($items) < 2) {
            return [];
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => end($items)['url'] . '#breadcrumb',
            'itemListElement' => array_map(
                static fn(array $item, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'item' => $item['url'],
                ],
                $items,
                array_keys($items)
            ),
        ];
    }

    public static function itemListNode(
        string $canonical,
        string $name,
        array $items,
        string $pathPrefix
    ): array {
        $elements = [];
        foreach ($items as $index => $item) {
            $slug = trim((string)($item['slug'] ?? ''));
            $itemName = trim((string)($item['title'] ?? ''));
            if ($slug === '' || $itemName === '') {
                continue;
            }
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => appUrl($pathPrefix . '/' . $slug),
                'name' => $itemName,
            ];
        }
        return [
            '@type' => 'ItemList',
            '@id' => $canonical . '#items',
            'name' => $name,
            'numberOfItems' => count($elements),
            'itemListElement' => $elements,
        ];
    }

    public static function faqNode(string $canonical, array $faqItems): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $canonical . '#faq',
            'mainEntity' => array_map(
                static fn(array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ],
                $faqItems
            ),
        ];
    }
}
