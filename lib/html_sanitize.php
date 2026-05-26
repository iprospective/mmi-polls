<?php
// Sanitization HTML pour le contenu du WYSIWYG.
// Whitelist stricte de balises/attributs ; nettoyage via DOMDocument.

function sanitize_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    $allowed_tags = [
        'p','br','strong','em','u','s','span',
        'ul','ol','li',
        'a',
        'h1','h2','h3','h4','h5','h6',
        'blockquote','pre','code',
        'hr',
    ];
    $allowed_attrs = [
        'a' => ['href', 'title'],
    ];

    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $wrapper = '<?xml encoding="UTF-8"?><div id="__sanitize_root__">' . $html . '</div>';
    $doc->loadHTML($wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $roots = $xpath->query('//div[@id="__sanitize_root__"]');
    if ($roots->length === 0) return '';
    $root = $roots->item(0);

    walk_sanitize($root, $allowed_tags, $allowed_attrs, true);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

function walk_sanitize(DOMNode $node, array $allowed_tags, array $allowed_attrs, bool $is_root = false): void {
    // Traiter d'abord les enfants (copie pour iterer en supprimant)
    if ($node->hasChildNodes()) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            walk_sanitize($child, $allowed_tags, $allowed_attrs, false);
        }
    }

    if ($is_root) return;
    if (!($node instanceof DOMElement)) return;

    $tag = strtolower($node->tagName);
    if (!in_array($tag, $allowed_tags, true)) {
        // Unwrap : on remonte les enfants au parent puis on supprime le nœud.
        while ($node->firstChild) {
            $node->parentNode->insertBefore($node->firstChild, $node);
        }
        $node->parentNode->removeChild($node);
        return;
    }

    $allowed = $allowed_attrs[$tag] ?? [];
    foreach (iterator_to_array($node->attributes) as $attr) {
        $name = strtolower($attr->name);
        if (!in_array($name, $allowed, true)) {
            $node->removeAttribute($attr->name);
        }
    }

    if ($tag === 'a' && $node->hasAttribute('href')) {
        $href = trim($node->getAttribute('href'));
        if (!preg_match('#^(https?://|mailto:|/|\#)#i', $href)) {
            $node->removeAttribute('href');
        } else {
            $node->setAttribute('href', $href);
            $node->setAttribute('rel', 'noopener noreferrer');
            $node->setAttribute('target', '_blank');
        }
    }
}

// Pour l'affichage : détecte si la description contient déjà du HTML
// (sinon on applique nl2br + escape pour rester compatible avec les
// anciennes descriptions saisies en plain text).
function render_description(?string $desc): string {
    $desc = (string)$desc;
    if ($desc === '') return '';
    if (preg_match('/<(p|br|h[1-6]|strong|em|ul|ol|li|a|blockquote|pre|code|hr|span)\b/i', $desc)) {
        return $desc;
    }
    return nl2br(htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}
