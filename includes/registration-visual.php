<aside class="registration-visual" aria-hidden="true">
    <picture class="registration-visual__media" data-theme-picture>
        <source type="image/avif"
                data-light-srcset="<?php echo escape($themePictureImages['light']['avif']); ?>"
                data-dark-srcset="<?php echo escape($themePictureImages['dark']['avif']); ?>"
                data-light-sizes="<?php echo escape($themePictureImages['light']['sizes']); ?>"
                data-dark-sizes="<?php echo escape($themePictureImages['dark']['sizes']); ?>">
        <source type="image/webp"
                data-light-srcset="<?php echo escape($themePictureImages['light']['webp']); ?>"
                data-dark-srcset="<?php echo escape($themePictureImages['dark']['webp']); ?>"
                data-light-sizes="<?php echo escape($themePictureImages['light']['sizes']); ?>"
                data-dark-sizes="<?php echo escape($themePictureImages['dark']['sizes']); ?>">
        <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="
             data-light-src="<?php echo escape($themePictureImages['light']['fallback']); ?>"
             data-dark-src="<?php echo escape($themePictureImages['dark']['fallback']); ?>"
             alt=""
             width="<?php echo (int) $themePictureImages['light']['width']; ?>"
             height="<?php echo (int) $themePictureImages['light']['height']; ?>"
             fetchpriority="high"
             decoding="async">
    </picture>
    <script>window.applyThemePicture(document.currentScript.previousElementSibling, document.documentElement.getAttribute('data-theme'));</script>
</aside>
