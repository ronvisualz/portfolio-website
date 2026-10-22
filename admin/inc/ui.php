<?php
/**
 * topper. admin — peças da linha de design (docs/ESPECIFICACAO-PLATAFORMA.md §11)
 *
 * Ícones, o menu agrupado, as preferências de cada pessoa (modo claro ou
 * escuro, menu recolhido, como quer ser tratada) e pequenos ajudantes de
 * marcação que as páginas partilham.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** Desenhos dos ícones (traço, 24×24). */
const ICONS = [
    'dashboard'    => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    'leads'        => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'pipeline'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 7v7M12 7v4M16 7v9"/>',
    'contactos'    => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/><circle cx="12" cy="8.5" r="2"/><path d="M15.5 14a3.5 3.5 0 0 0-7 0"/>',
    'marketing'    => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'negocios'     => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    'orcamentacao' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M16 14v4M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M8 18h.01M12 18h.01"/>',
    'criativo'     => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/>',
    'tarefas'      => '<path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'drive'        => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
    'financeiro'   => '<path d="M4 10h12M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/>',
    'relatorios'   => '<path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/>',
    'equipa'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'conhecimento' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    'tools'        => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    'migracao'     => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
    'search'       => '<circle cx="11" cy="11" r="7.5"/><path d="m21 21-4.3-4.3"/>',
    'bell'         => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    'sun'          => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
    'moon'         => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    'menu'         => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    'sidebar'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/><path d="m16 15-3-3 3-3"/>',
    'x'            => '<path d="M18 6 6 18M6 6l12 12"/>',
    'pencil'       => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
    'plus'         => '<path d="M5 12h14M12 5v14"/>',
    'external'     => '<path d="M15 3h6v6M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    'phone'        => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
    'mail'         => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    'note'         => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M16 13H8M16 17H8M10 9H8"/>',
    'clock'        => '<circle cx="12" cy="12" r="9.5"/><path d="M12 6.5V12l3.5 2"/>',
    'alert'        => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
    'check'        => '<path d="M20 6 9 17l-5-5"/>',
    'arrow'        => '<path d="M5 12h14M12 5l7 7-7 7"/>',
    'stage'        => '<circle cx="12" cy="12" r="3"/><path d="M3 12h6M15 12h6"/>',
    'logout'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
    'user'         => '<circle cx="12" cy="8" r="4.5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
    'download'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
    'link'         => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'archive'      => '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/>',
    'undo'         => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
    'won'          => '<circle cx="12" cy="12" r="9.5"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    'eye'          => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    'calendar'     => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'meeting'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
    'enter'        => '<path d="M9 10l-5 5 5 5"/><path d="M20 4v7a4 4 0 0 1-4 4H4"/>',
    'file'         => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    'star'         => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
    'user-arrow'   => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 11.5-5.4"/><path d="M15.5 18h6M18.5 15l3 3-3 3"/>',
    'arrows'       => '<path d="m17 3 4 4-4 4M3 7h18M7 21l-4-4 4-4M21 17H3"/>',
    'phone-off'    => '<path d="M10.7 13.3a16 16 0 0 0 3.4 2.6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.4 19.4 0 0 1-3.33-2.67m-2.67-3.34a19.8 19.8 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91"/><path d="M22 2 2 22"/>',
    'box'          => '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/>',
    'bubble'       => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'pin'          => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    'checklist'    => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m8.5 13 2 2 4-4"/>',
    'utensils'     => '<path d="M3 2v7a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6a2 2 0 0 0 2 2h3Zm0 0v7"/>',
    'wine'         => '<path d="M8 22h8"/><path d="M7 10h10"/><path d="M12 15v7"/><path d="M12 15a5 5 0 0 0 5-5c0-2-.5-4-2-8H9c-1.5 4-2 6-2 8a5 5 0 0 0 5 5Z"/>',
    'refresh'      => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
    'chev-left'    => '<path d="m15 18-6-6 6-6"/>',
    'chev-right'   => '<path d="m9 18 6-6-6-6"/>',
    'chev-up'      => '<path d="m18 15-6-6-6 6"/>',
    'chev-down'    => '<path d="m6 9 6 6 6-6"/>',
    'whatsapp'     => '<path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9L3 21"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/>',
    'globe'        => '<circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19"/><path d="M12 2.5a14.5 14.5 0 0 1 4 9.5 14.5 14.5 0 0 1-4 9.5 14.5 14.5 0 0 1-4-9.5 14.5 14.5 0 0 1 4-9.5z"/>',
    'instagram'    => '<rect x="2.5" y="2.5" width="19" height="19" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>',
    'linkedin'     => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>',
    'facebook'     => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
    'youtube'      => '<path d="M2.5 17a24 24 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.6 49.6 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24 24 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.6 49.6 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><path d="m10 15 5-3-5-3z"/>',
    'vimeo'        => '<path d="M3 9.5 4 10.7c1-.7 1.6-1 1.9-.7.6.5 1.7 6.8 3.3 8.2 1.5 1.3 4.3-.7 6.6-3.6C18.3 11.4 21 7.7 21 6.1c0-2.6-2.3-2.8-4.1-1.7-1 .6-1.8 1.7-2.4 3.4 1.6-.9 2.7-.4 2.1 1.6-.6 1.9-2.6 4.6-3.4 4.6-.8 0-1.6-6-2.6-8.2C9.8 3.6 7.9 4.6 3 9.5z"/>',
    'tiktok'       => '<path d="M9 12a4 4 0 1 0 4 4V2a5 5 0 0 0 5 5"/>',
    'keyboard'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M6 9h.01M10 9h.01M14 9h.01M18 9h.01M6 13h.01M18 13h.01M9 15h6"/>',
    'heart'        => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
    'building'     => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>',
    'euro'         => '<path d="M4 10h12M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/>',
    'upload'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/>',
    'merge'        => '<path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.17 2.87L4 22"/><path d="m20 22-5-5"/>',
    'pause'        => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
    'dots'         => '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',
    'trend'        => '<path d="m3 17 6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
    'headphones'   => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1v-6h3zM3 19a2 2 0 0 0 2 2h1v-6H3z"/>',
    'cake'         => '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20M7 8v3M12 8v3M17 8v3M7 4h.01M12 4h.01M17 4h.01"/>',
    'plane'        => '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>',
    'org'          => '<rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/><path d="M12 7v5M5 17v-3h14v3"/>',
    'send'         => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    'video'        => '<path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
    'bulb'         => '<path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1V17h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2Z"/>',
    'sparkle'      => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8Z"/>',
    'copy'         => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    'scissors'     => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.12 15.88M14.47 14.48 20 20M8.12 8.12 12 12"/>',
    'cog'          => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1.08 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
    'ban'          => '<circle cx="12" cy="12" r="9"/><path d="m5.7 5.7 12.6 12.6"/>',
    'package'      => '<path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
    'hand'         => '<path d="M18 11V6a2 2 0 0 0-4 0M14 10V4a2 2 0 0 0-4 0v2M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/>',
    'camera'       => '<path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
    'thumb'        => '<path d="M7 10v12"/><path d="M15 5.9 14 10h5.8a2 2 0 0 1 2 2.3l-1.4 8A2 2 0 0 1 18.4 22H7V10l4-8a3 3 0 0 1 4 3.9Z"/>',
    'xlogo'        => '<path d="M4 4l16 16M20 4 4 20" stroke-width="2.2"/>',
    'power'        => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
];

/** Ícone de cada rede social reconhecida (social_kind → ícone). */
const SOCIAL_ICONS = ['instagram' => 'instagram', 'linkedin' => 'linkedin', 'facebook' => 'facebook', 'youtube' => 'youtube',
                      'vimeo' => 'vimeo', 'tiktok' => 'tiktok', 'x' => 'x', 'behance' => 'globe', 'site' => 'globe'];

/**
 * Atalhos de teclado e gestos da plataforma (Conhecimento › Navegação).
 * Acrescentar aqui sempre que surgir um atalho novo (§12.11).
 */
const SHORTCUTS = [
    ['⌘K / Ctrl+K',  'Pesquisa global (IDs, negócios, leads, contactos, ficheiros, tarefas, «ir para»)', 'Em todas as páginas'],
    ['Enter',         'Abrir o resultado escolhido', 'Pesquisa'],
    ['⌘ Enter / Ctrl+Enter', 'Abrir o resultado no painel lateral', 'Pesquisa'],
    ['↑ / ↓',         'Escolher o resultado', 'Pesquisa'],
    ['[',             'Recolher ou expandir o menu lateral', 'Em todas as páginas'],
    ['N',             'Nova lead', 'Leads'],
    ['N',             'Novo contacto', 'Contactos'],
    ['N',             'Nova linha de custo', 'Folha de orçamento'],
    ['I',             'Nova ideia de conteúdo (Marketing)', 'Em todas as páginas'],
    ['C',             'Abrir o chat da equipa (Esc fecha; Enter envia, Shift+Enter muda de linha)', 'Em todas as páginas'],
    ['N',             'Nova ideia de conteúdo', 'Marketing'],
    ['E',             'Editar a lead aberta (Esc cancela, ⌘ Enter / Ctrl+Enter grava)', 'Painel lateral e ficha da lead'],
    ['E',             'Editar o contacto aberto (Esc cancela, ⌘ Enter / Ctrl+Enter grava)', 'Painel lateral e ficha do contacto'],
    ['↑ / ↓  ou  J / K', 'Lead ou contacto anterior / seguinte (pela ordem e filtros da lista)', 'Painel lateral (Leads e Contactos)'],
    ['Esc',           'Fechar o painel, o formulário ou a pesquisa', 'Em todas as páginas'],
    ['Enter', 'Gravar uma edição no próprio campo (nos textos longos: ⌘ Enter ou Ctrl+Enter)', 'Edição no campo'],
    ['Toque longo',   'Ver o nome de um ícone', 'Ecrãs de toque'],
    ['Passar o cursor', 'Ver o nome de um ícone (~0,3 s)', 'Rato, touchpad, trackpad'],
];

/** Um ícone. Decorativo por omissão; o nome vai no elemento que o contém (aria-label + data-tip). */
function icon(string $name, string $class = ''): string {
    $d = ICONS[$name] ?? ICONS['file'];
    return '<svg class="ico' . ($class !== '' ? ' ' . $class : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/**
 * Um botão só com ícone: o nome serve de dica (ao passar o cursor, com o
 * teclado ou com toque longo) e de nome acessível. Nunca um ícone sem nome.
 */
function icon_button(string $icon, string $label, array $attr = []): string {
    $tag = isset($attr['href']) ? 'a' : 'button';
    $attr += ['class' => 'ibtn'];
    if ($tag === 'button') $attr += ['type' => 'button'];
    $h = '<' . $tag;
    foreach ($attr as $k => $v) {
        if ($v === null || $v === false) continue;
        $h .= ' ' . $k . ($v === true ? '' : '="' . e((string)$v) . '"');
    }
    return $h . ' aria-label="' . e($label) . '" data-tip>' . icon($icon) . '</' . $tag . '>';
}

// ── menu lateral ───────────────────────────────────────────

/** Separadores do menu: chave → [nome, endereço, ícone]. A chave é a aba de permissões (ou NAV_TABS). */
const NAV_ITEMS = [
    'dashboard'    => ['Dashboard',       '/admin/dashboard.php',                  'dashboard'],
    'leads'        => ['Leads',           '/admin/leads.php',                      'leads'],
    'pipeline'     => ['Pipeline',        '/admin/pipeline.php',                   'pipeline'],
    'contactos'    => ['Contactos',       '/admin/contactos.php',                  'contactos'],
    'marketing'    => ['Marketing',       '/admin/marketing.php',                  'marketing'],
    'negocios'     => ['Negócios',        '/admin/negocios.php',                   'negocios'],
    'orcamentacao' => ['Orçamentação',    '/admin/orcamentacao.php',               'orcamentacao'],
    'criativo'     => ['Centro Criativo', '/admin/brevemente.php?t=criativo',      'criativo'],
    'calendar'     => ['Tarefas',         '/admin/calendar.php',                   'tarefas'],
    'drive'        => ['Drive',           '/admin/drive.php',                      'drive'],
    'financeiro'   => ['Financeiro',      '/admin/financeiro.php',                 'financeiro'],
    'relatorios'   => ['Relatórios',      '/admin/relatorios.php',                 'relatorios'],
    'equipa'       => ['Equipa',          '/admin/equipa.php',                     'equipa'],
    'conhecimento' => ['Conhecimento',    '/admin/conhecimento.php',               'conhecimento'],
    'tools'        => ['Tools',           '/admin/tools.php',                      'tools'],
];

/** Os grupos do menu (§11.18). */
const NAV_GROUPS = [
    'Geral'     => ['dashboard'],
    'Comercial' => ['leads', 'pipeline', 'contactos', 'marketing'],
    'Produção'  => ['negocios', 'orcamentacao', 'criativo', 'calendar', 'drive'],
    'Gestão'    => ['financeiro', 'relatorios', 'equipa', 'conhecimento', 'tools'],
];

/** Separador do menu → aba de permissões. */
const NAV_TABS = ['calendar' => 'tarefas'];

// ── preferências de cada pessoa ────────────────────────────

/** display_name, pref_theme, pref_nav (e a cor) da conta com sessão — guardadas na conta, não no browser. */
function ui_prefs(): array {
    static $p = null;
    if ($p !== null) return $p;
    $p = ['display_name' => '', 'pref_theme' => '', 'pref_nav' => '', 'color' => ''];
    $u = current_user();
    if ($u === null) return $p;
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT display_name, pref_theme, pref_nav, color FROM users WHERE id = ?');
        $st->execute([(int)$u['id']]);
        $p = array_merge($p, array_map('strval', $st->fetch() ?: []));
    } catch (Throwable $e) {
        // Antes do passo 12 da base de dados as colunas ainda não existem.
    }
    if (!in_array($p['pref_theme'], ['', 'light', 'dark'], true)) $p['pref_theme'] = '';
    if (!in_array($p['pref_nav'], ['', 'closed'], true)) $p['pref_nav'] = '';
    return $p;
}

/** Como a pessoa quer ser tratada; se não disse, o primeiro nome. */
function greet_name(?array $u = null): string {
    $u ??= current_user();
    $d = trim(ui_prefs()['display_name'] ?? '');
    if ($d !== '') return $d;
    return explode(' ', trim((string)($u['name'] ?? '')))[0];
}

/** Iniciais para o avatar. */
function initials(string $name): string {
    $parts = array_values(array_filter(explode(' ', trim($name)), 'strlen'));
    if (!$parts) return '?';
    $a = mb_substr($parts[0], 0, 1);
    $b = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($a . $b);
}

/** O logótipo «topper.» (cor do texto à volta). */
function wordmark_svg(string $class = 'wm'): string {
    return '<svg class="' . $class . '" viewBox="0 0 432.74 121.88" aria-hidden="true" focusable="false">'
         . '<path fill="currentColor" fill-rule="nonzero" d="M 64.7 55.0 C 64.7 60.6 66.2 65.1 69.2 68.3 C 72.3 71.6 76.4 73.3 81.5 73.3 C 86.7 73.3 90.8 71.6 93.8 68.3 C 96.9 65.1 98.4 60.7 98.4 55.2 C 98.4 49.8 96.9 45.4 93.8 42.0 C 90.8 38.7 86.7 37.0 81.5 37.0 C 76.4 37.0 72.3 38.7 69.2 42.0 C 66.2 45.4 64.7 49.7 64.7 55.0 M 45.1 54.8 C 45.1 49.8 46.0 45.2 47.8 41.0 C 49.6 36.8 52.1 33.2 55.3 30.1 C 58.6 27.1 62.4 24.7 66.9 22.9 C 71.3 21.2 76.2 20.3 81.5 20.3 C 86.8 20.3 91.7 21.1 96.2 22.8 C 100.6 24.6 104.5 26.9 107.7 30.0 C 111.0 33.1 113.5 36.8 115.3 41.0 C 117.1 45.3 118.0 50.0 118.0 55.2 C 118.0 60.3 117.1 65.0 115.3 69.3 C 113.5 73.6 111.0 77.3 107.7 80.3 C 104.5 83.4 100.6 85.8 96.1 87.5 C 91.6 89.2 86.7 90.1 81.3 90.1 C 76.0 90.1 71.2 89.2 66.7 87.5 C 62.3 85.8 58.5 83.4 55.3 80.3 C 52.1 77.3 49.6 73.5 47.8 69.2 C 46.0 64.8 45.1 60.0 45.1 54.8 M 146.6 55.0 C 146.6 57.6 147.0 59.9 147.8 62.1 C 148.7 64.3 149.8 66.3 151.4 67.9 C 152.9 69.5 154.7 70.8 156.8 71.7 C 158.9 72.7 161.3 73.2 163.8 73.2 C 166.4 73.2 168.7 72.7 170.8 71.8 C 172.9 70.9 174.6 69.6 176.1 68.0 C 177.5 66.4 178.6 64.5 179.5 62.3 C 180.3 60.1 180.7 57.7 180.7 55.2 C 180.7 49.8 179.2 45.3 176.3 41.9 C 173.4 38.5 169.2 36.8 163.9 36.8 C 158.7 36.8 154.5 38.5 151.4 41.9 C 148.2 45.3 146.6 49.7 146.6 55.0 M 147.6 22.0 L 147.4 29.7 L 147.6 29.7 C 150.6 26.6 153.9 24.2 157.3 22.6 C 160.7 21.0 164.5 20.1 168.7 20.1 C 173.4 20.1 177.8 21.1 181.7 22.9 C 185.6 24.8 189.0 27.3 191.7 30.4 C 194.5 33.6 196.7 37.3 198.2 41.5 C 199.6 45.7 200.4 50.3 200.4 55.2 C 200.4 59.9 199.6 64.4 198.1 68.6 C 196.6 72.8 194.4 76.5 191.6 79.6 C 188.8 82.8 185.5 85.3 181.6 87.1 C 177.7 88.9 173.5 89.8 168.8 89.8 C 164.5 89.8 160.7 89.0 157.2 87.5 C 153.8 86.0 150.5 83.6 147.6 80.3 L 147.4 80.3 C 147.6 82.4 147.7 84.3 147.7 86.0 L 147.7 121.8 L 128.1 121.8 L 128.1 22.0 Z M 147.6 22.0 M 228.9 55.0 C 228.9 57.6 229.3 59.9 230.1 62.1 C 230.9 64.3 232.1 66.3 233.6 67.9 C 235.1 69.5 237.0 70.8 239.1 71.7 C 241.2 72.7 243.5 73.2 246.0 73.2 C 248.7 73.2 251.0 72.7 253.1 71.8 C 255.1 70.9 256.9 69.6 258.3 68.0 C 259.8 66.4 260.9 64.5 261.7 62.3 C 262.5 60.1 262.9 57.7 262.9 55.2 C 262.9 49.8 261.5 45.3 258.5 41.9 C 255.6 38.5 251.5 36.8 246.2 36.8 C 241.0 36.8 236.8 38.5 233.6 41.9 C 230.5 45.3 228.9 49.7 228.9 55.0 M 229.8 22.0 L 229.7 29.7 L 229.8 29.7 C 232.9 26.6 236.1 24.2 239.6 22.6 C 243.0 21.0 246.8 20.1 250.9 20.1 C 255.7 20.1 260.0 21.1 263.9 22.9 C 267.9 24.8 271.2 27.3 274.0 30.4 C 276.8 33.6 278.9 37.3 280.4 41.5 C 281.9 45.7 282.6 50.3 282.6 55.2 C 282.6 59.9 281.9 64.4 280.3 68.6 C 278.8 72.8 276.7 76.5 273.9 79.6 C 271.1 82.8 267.7 85.3 263.9 87.1 C 260.0 88.9 255.7 89.8 251.0 89.8 C 246.8 89.8 243.0 89.0 239.5 87.5 C 236.0 86.0 232.8 83.6 229.8 80.3 L 229.7 80.3 C 229.9 82.4 230.0 84.3 230.0 86.0 L 230.0 121.8 L 210.4 121.8 L 210.4 22.0 Z M 229.8 22.0 M 309.2 46.4 L 335.9 46.4 C 335.4 42.8 334.0 40.0 331.7 38.0 C 329.3 36.0 326.3 35.0 322.6 35.0 C 319.0 35.0 316.1 36.1 313.8 38.1 C 311.5 40.2 310.0 43.0 309.2 46.4 M 322.3 20.3 C 327.5 20.3 332.1 21.1 336.2 22.7 C 340.3 24.3 343.8 26.7 346.7 29.7 C 349.5 32.8 351.8 36.5 353.3 40.9 C 354.9 45.3 355.7 50.2 355.7 55.7 L 355.7 59.2 L 308.4 59.2 C 308.8 64.1 310.2 68.0 312.7 70.9 C 315.2 73.9 318.7 75.3 323.3 75.3 C 326.4 75.3 329.2 74.6 331.5 73.0 C 333.8 71.5 335.8 69.1 337.6 65.8 L 354.8 71.5 C 351.8 77.7 347.5 82.4 341.9 85.5 C 336.3 88.5 330.0 90.1 323.0 90.1 C 317.9 90.1 313.2 89.2 309.0 87.6 C 304.9 86.0 301.2 83.7 298.2 80.7 C 295.1 77.6 292.7 74.0 291.1 69.7 C 289.4 65.4 288.6 60.6 288.6 55.3 C 288.6 50.0 289.4 45.2 291.1 40.8 C 292.7 36.5 295.1 32.8 298.0 29.8 C 301.0 26.8 304.6 24.4 308.7 22.8 C 312.8 21.1 317.4 20.3 322.3 20.3 M 365.8 22.0 L 385.4 22.0 L 385.2 28.8 L 385.6 28.8 C 388.1 25.7 390.5 23.5 392.9 22.2 C 395.3 20.9 398.3 20.3 402.0 20.3 C 404.9 20.3 408.2 21.3 411.9 23.3 L 406.6 42.0 C 405.2 41.1 403.7 40.3 402.3 39.7 C 400.9 39.1 399.3 38.8 397.6 38.8 C 389.5 38.8 385.4 44.6 385.4 56.1 L 385.4 88.2 L 365.8 88.2 Z M 365.8 22.0 M 409.2 79.0 C 409.2 75.7 410.4 73.1 412.7 71.0 C 415.0 68.9 417.8 67.9 420.9 67.9 C 424.1 67.9 426.8 68.9 429.2 71.0 C 431.5 73.1 432.7 75.7 432.7 79.0 C 432.7 82.2 431.5 84.9 429.2 86.9 C 426.8 89.0 424.1 90.1 420.9 90.1 C 417.8 90.1 415.0 89.0 412.7 86.9 C 410.4 84.9 409.2 82.2 409.2 79.0 M 10.5 88.2 L 30.0 88.2 L 30.0 48.7 L 10.5 48.7 Z M 10.5 88.2 M 31.3 23.8 L 31.3 0.0 L 10.5 0.0 L 10.5 23.8 C 10.5 24.6 9.8 25.2 9.1 25.0 L 1.4 23.6 C 0.6 23.5 0.0 24.0 0.0 24.8 L 0.0 40.7 L 41.8 40.7 L 41.8 24.8 C 41.8 24.0 41.1 23.5 40.4 23.6 L 32.7 25.0 C 32.0 25.2 31.3 24.6 31.3 23.8"/></svg>';
}

// ── pequenas peças de marcação ─────────────────────────────

/**
 * As vistas guardadas de uma lista (§11.12): «Todos», «Os meus», «Em risco», «Este mês».
 * $views: chave → nome; $current: a vista aberta; $url: fn(chave) → endereço.
 */
function views_bar(array $views, string $current, callable $url, array $counts = []): string {
    $h = '<nav class="views" aria-label="Vistas">';
    foreach ($views as $k => $label) {
        $n = isset($counts[$k]) ? ' <span class="mono">' . (int)$counts[$k] . '</span>' : '';
        $h .= '<a href="' . e($url($k)) . '"' . ($k === $current ? ' class="on" aria-current="true"' : '') . '>' . e($label) . $n . '</a>';
    }
    return $h . '</nav>';
}

/** Um valor editável no próprio campo (§11.8): mostra o valor e o lápis; o resto é com o app.js. */
function inline_field(string $entity, int $id, string $field, string $display, array $opt = []): string {
    $type = $opt['type'] ?? 'text';
    $attr = ' data-ie="' . e($entity) . '" data-id="' . $id . '" data-field="' . e($field) . '" data-type="' . e($type) . '"';
    if (isset($opt['value'])) $attr .= ' data-value="' . e((string)$opt['value']) . '"';
    if (isset($opt['options'])) $attr .= " data-options='" . e(json_encode($opt['options'], JSON_UNESCAPED_UNICODE)) . "'";
    $label = $opt['label'] ?? 'Editar';
    $edit = !empty($opt['edit'])
        ? '<button type="button" class="ie-b" aria-label="' . e($label) . '" data-tip>' . icon('pencil') . '</button>'
        : '';
    return '<span class="ie"' . ($edit !== '' ? $attr : '') . '><span class="ie-v">' . $display . '</span>' . $edit . '</span>';
}

/** «há 3 dias», «hoje», «ontem». */
function ago_days(?string $date): string {
    if (!$date) return '';
    $d = (new DateTimeImmutable(substr($date, 0, 10)))->diff(new DateTimeImmutable(today_lisbon()))->days;
    return match (true) {
        $d === 0 => 'hoje',
        $d === 1 => 'ontem',
        default  => "há $d dias",
    };
}

// ── «Voltar» pelo caminho que se fez ───────────────────────
//
// Quando se passa de uma aba para outra a partir de um registo (ex.: ficha do
// negócio → orçamento → contacto), cada ligação leva consigo a página de onde
// saiu (?de=…), e o «Voltar» de cada página faz o caminho inverso, passo a passo
// (cada «de» guarda o seu próprio «de»). Sem «de», volta à lista da aba.

/** A página de onde se veio (?de=), só dentro do /admin/; null se não houver. */
function nav_from(): ?string {
    $de = (string)($_GET['de'] ?? '');
    if ($de === '' || strlen($de) > 1800 || !preg_match('~^/admin/[a-z_]+\.php(\?[^#\s]*)?$~', $de)) return null;
    return $de;
}

/** A página atual, para levar como «de» (sem o pedido de formulários abertos). */
function nav_here(): string {
    $u = (string)($_SERVER['REQUEST_URI'] ?? '/admin/');
    $u = preg_replace('~[?&](avaliar|fecho|novo|nova|cost|delcost)=[^&]*~', '', $u);
    if (strpos($u, '?') === false && strpos($u, '&') !== false) $u = preg_replace('~&~', '?', $u, 1);
    return strlen($u) > 1800 ? (string)strtok($u, '?') : $u;
}

/** $url com o «de» desta página (ou de $from). */
function nav_to(string $url, ?string $from = null): string {
    $from ??= nav_here();
    [$base, $frag] = array_pad(explode('#', $url, 2), 2, '');
    return $base . (strpos($base, '?') === false ? '?' : '&') . 'de=' . rawurlencode($from) . ($frag !== '' ? '#' . $frag : '');
}

/** «de» que esta página deve passar às suas próprias ligações internas (para não o perder). */
function nav_keep(): string {
    $f = nav_from();
    return $f ? '&de=' . rawurlencode($f) : '';
}

/** O nome do sítio para onde o «Voltar» leva. */
function nav_label(string $url): string {
    $p = basename((string)parse_url($url, PHP_URL_PATH));
    return match ($p) {
        'negocio.php' => 'Voltar ao negócio', 'negocios.php' => 'Voltar aos negócios',
        'orcamento.php' => 'Voltar ao orçamento', 'orcamentacao.php' => 'Voltar às folhas',
        'contact.php' => 'Voltar ao contacto', 'contactos.php' => 'Voltar aos contactos',
        'lead.php' => 'Voltar à lead', 'leads.php' => 'Voltar às leads',
        'pipeline.php' => 'Voltar ao Pipeline', 'financeiro.php' => 'Voltar ao Financeiro',
        'relatorios.php' => 'Voltar aos Relatórios', 'calendar.php' => 'Voltar às Tarefas',
        'dashboard.php' => 'Voltar ao Dashboard',
        default => 'Voltar',
    };
}
