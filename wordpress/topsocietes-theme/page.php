<?php
/**
 * Template: Page générique (non-Elementor)
 */
get_header();
?>
<style>
.ts-prose-page{max-width:820px;margin:48px auto 80px;padding:0 24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1f2937}
.ts-prose-page h1{font-size:32px;font-weight:800;color:#111827;margin:0 0 24px;line-height:1.2;border-bottom:3px solid #f0f4ff;padding-bottom:16px}
.ts-prose-page h2{font-size:22px;font-weight:700;color:#1e3a8a;margin:36px 0 12px}
.ts-prose-page h3{font-size:17px;font-weight:700;color:#374151;margin:28px 0 10px}
.ts-prose-page p{font-size:15px;line-height:1.8;color:#374151;margin:0 0 16px}
.ts-prose-page ul,.ts-prose-page ol{font-size:15px;line-height:1.8;color:#374151;margin:0 0 16px;padding-left:24px}
.ts-prose-page li{margin-bottom:6px}
.ts-prose-page a{color:#1e3a8a;text-decoration:underline}
.ts-prose-page a:hover{color:#3b82f6}
.ts-prose-page blockquote{border-left:4px solid #6366f1;margin:20px 0;padding:12px 20px;background:#f5f3ff;border-radius:0 8px 8px 0;font-style:italic;color:#4338ca}
.ts-prose-page img{max-width:100%;height:auto;border-radius:10px;margin:16px 0}
.ts-prose-page table{width:100%;border-collapse:collapse;font-size:14px;margin:20px 0}
.ts-prose-page th{background:#1e3a8a;color:#fff;padding:10px 14px;text-align:left;font-weight:600}
.ts-prose-page td{padding:10px 14px;border-bottom:1px solid #e5e7eb;color:#374151}
.ts-prose-page tr:nth-child(even) td{background:#f9fafb}
.ts-prose-page strong{color:#111827;font-weight:700}
.ts-prose-page hr{border:none;border-top:2px solid #e5e7eb;margin:32px 0}
@media(max-width:640px){.ts-prose-page{margin:24px auto 48px;padding:0 16px}.ts-prose-page h1{font-size:24px}}
</style>
<div class="ts-prose-page">
    <?php while ( have_posts() ) : the_post(); ?>
        <h1><?php the_title(); ?></h1>
        <?php the_content(); ?>
    <?php endwhile; ?>
</div>
<?php get_footer();
