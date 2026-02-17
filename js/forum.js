/**
 * HAFO.de Forum JavaScript
 * Handles forum categories, topics, and posts
 */

const API_BASE = 'api';

// State
let currentUser = null;
let csrfToken = null;
let currentView = 'categories'; // 'categories', 'topics', 'topic'
let currentCategoryId = null;
let currentTopicId = null;
let currentPage = 1;
let totalPages = 1;

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    checkAuth();
    parseUrl();
});

// Handle browser back/forward
window.addEventListener('popstate', () => {
    parseUrl();
});

/**
 * Parse URL and load appropriate view
 */
function parseUrl() {
    const params = new URLSearchParams(window.location.search);
    const categoryId = params.get('category');
    const topicId = params.get('topic');
    currentPage = parseInt(params.get('page')) || 1;

    if (topicId) {
        loadTopic(parseInt(topicId));
    } else if (categoryId) {
        loadCategory(parseInt(categoryId));
    } else {
        loadCategories();
    }
}

/**
 * Check if user is logged in
 */
async function checkAuth() {
    try {
        const res = await fetch(`${API_BASE}/check-auth.php`, {
            credentials: 'include'
        });
        const data = await res.json();

        if (data.authenticated) {
            currentUser = {
                id: data.user_id,
                username: data.username,
                displayName: data.display_name,
                role: data.role
            };
            csrfToken = data.csrf_token;
            updateUserStatus();
        } else {
            currentUser = null;
            csrfToken = data.csrf_token;
            updateUserStatus();
        }
    } catch (e) {
        console.error('Auth check failed:', e);
    }
}

/**
 * Update user status display in header
 */
function updateUserStatus() {
    const el = document.getElementById('user-status');
    if (!el) return;

    if (currentUser) {
        el.innerHTML = `
            <span style="color: var(--color-text-muted); margin-right: var(--spacing-sm);">
                Hallo, <strong>${escapeHtml(currentUser.displayName || currentUser.username)}</strong>
            </span>
            <a href="admin/index.html" class="btn btn-secondary" style="font-size: 0.85rem;">Admin</a>
        `;
        document.getElementById('new-topic-btn').style.display = 'inline-flex';
    } else {
        el.innerHTML = `<a href="admin/index.html" class="btn btn-primary">Login</a>`;
        document.getElementById('new-topic-btn').style.display = 'none';
    }
}

// ============================================
// CATEGORIES VIEW
// ============================================

/**
 * Load all forum categories
 */
async function loadCategories() {
    currentView = 'categories';
    currentCategoryId = null;
    currentTopicId = null;

    updateBreadcrumb([]);
    document.getElementById('forum-title').innerHTML = 'Community <span>Forum</span>';
    document.getElementById('new-topic-btn').style.display = 'none';

    try {
        const res = await fetch(`${API_BASE}/forum-categories.php`);
        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || 'Fehler beim Laden');
        }

        // Update stats
        if (data.stats) {
            document.getElementById('stat-categories').textContent = data.stats.categories || 0;
            document.getElementById('stat-topics').textContent = data.stats.topics || 0;
            document.getElementById('stat-posts').textContent = data.stats.posts || 0;
        }

        // Update CSRF token
        if (data.csrf_token) {
            csrfToken = data.csrf_token;
        }

        renderCategories(data.categories || []);

    } catch (e) {
        console.error('Error loading categories:', e);
        document.getElementById('forum-content').innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">❌</div>
                <p>Fehler beim Laden der Kategorien</p>
                <button class="btn btn-secondary" onclick="loadCategories()">Erneut versuchen</button>
            </div>
        `;
    }
}

/**
 * Render categories list
 */
function renderCategories(categories) {
    const container = document.getElementById('forum-content');

    if (categories.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">📁</div>
                <p>Noch keine Kategorien vorhanden</p>
            </div>
        `;
        return;
    }

    container.innerHTML = categories.map(cat => `
        <div class="category-card" onclick="navigateTo('category', ${cat.id})">
            <div class="category-header">
                <div class="category-info">
                    <div class="category-name">${escapeHtml(cat.name)}</div>
                    ${cat.description ? `<div class="category-description">${escapeHtml(cat.description)}</div>` : ''}
                </div>
                <div class="category-stats">
                    <div class="category-stat">
                        <span class="category-stat-value">${cat.topic_count || 0}</span>
                        <span class="category-stat-label">Themen</span>
                    </div>
                    <div class="category-stat">
                        <span class="category-stat-value">${cat.post_count || 0}</span>
                        <span class="category-stat-label">Beiträge</span>
                    </div>
                </div>
            </div>
        </div>
    `).join('');

    document.getElementById('pagination-container').innerHTML = '';
}

// ============================================
// CATEGORY/TOPICS VIEW
// ============================================

/**
 * Load topics of a category
 */
async function loadCategory(categoryId) {
    currentView = 'topics';
    currentCategoryId = categoryId;
    currentTopicId = null;

    if (currentUser) {
        document.getElementById('new-topic-btn').style.display = 'inline-flex';
    }

    try {
        const res = await fetch(`${API_BASE}/forum-topics.php?category=${categoryId}&limit=20&offset=${(currentPage - 1) * 20}`);
        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || 'Fehler beim Laden');
        }

        // Update CSRF token
        if (data.csrf_token) {
            csrfToken = data.csrf_token;
        }

        // Update breadcrumb and title
        const categoryName = data.category?.name || 'Kategorie';
        updateBreadcrumb([
            { label: categoryName, url: `?category=${categoryId}` }
        ]);
        document.getElementById('forum-title').innerHTML = `${escapeHtml(categoryName)}`;

        // Pagination
        totalPages = data.pagination?.pages || 1;

        renderTopics(data.topics || [], data.category);

    } catch (e) {
        console.error('Error loading category:', e);
        document.getElementById('forum-content').innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">❌</div>
                <p>Fehler beim Laden der Themen</p>
                <button class="btn btn-secondary" onclick="loadCategory(${categoryId})">Erneut versuchen</button>
            </div>
        `;
    }
}

/**
 * Render topics list
 */
function renderTopics(topics, category) {
    const container = document.getElementById('forum-content');

    if (topics.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">💬</div>
                <p>Noch keine Themen in dieser Kategorie</p>
                ${currentUser ? `<button class="btn btn-primary" onclick="showNewTopicForm()">Erstes Thema erstellen</button>` : ''}
            </div>
        `;
        document.getElementById('pagination-container').innerHTML = '';
        return;
    }

    container.innerHTML = `
        <div class="topic-list">
            ${topics.map(topic => {
                let iconClass = '';
                let icon = '💬';
                if (topic.is_sticky) {
                    iconClass = 'sticky';
                    icon = '📌';
                }
                if (topic.is_locked) {
                    iconClass = 'locked';
                    icon = '🔒';
                }

                return `
                    <div class="topic-row" onclick="navigateTo('topic', ${topic.id})">
                        <div class="topic-icon ${iconClass}">${icon}</div>
                        <div class="topic-info">
                            <a href="?topic=${topic.id}" class="topic-title" onclick="event.stopPropagation()">
                                ${escapeHtml(topic.title)}
                            </a>
                            <div class="topic-meta">
                                von <strong>${escapeHtml(topic.author_display_name || topic.author_name || 'Unbekannt')}</strong>
                                • ${formatDate(topic.created_at)}
                            </div>
                        </div>
                        <div class="topic-stats">
                            <div class="topic-stat">
                                <div class="topic-stat-value">${topic.post_count || 0}</div>
                                <div class="topic-stat-label">Antworten</div>
                            </div>
                            <div class="topic-stat">
                                <div class="topic-stat-value">${topic.view_count || 0}</div>
                                <div class="topic-stat-label">Aufrufe</div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;

    renderPagination();
}

// ============================================
// TOPIC/POSTS VIEW
// ============================================

/**
 * Load a single topic with its posts
 */
async function loadTopic(topicId) {
    currentView = 'topic';
    currentTopicId = topicId;
    document.getElementById('new-topic-btn').style.display = 'none';

    try {
        const res = await fetch(`${API_BASE}/forum-topics.php?id=${topicId}&limit=20&offset=${(currentPage - 1) * 20}`);
        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || 'Fehler beim Laden');
        }

        // Update CSRF token
        if (data.csrf_token) {
            csrfToken = data.csrf_token;
        }

        currentCategoryId = data.topic?.category_id;

        // Update breadcrumb and title
        const topic = data.topic;
        updateBreadcrumb([
            { label: topic.category_name || 'Kategorie', url: `?category=${topic.category_id}` },
            { label: topic.title, url: `?topic=${topicId}` }
        ]);
        document.getElementById('forum-title').innerHTML = escapeHtml(topic.title);

        // Pagination
        totalPages = data.pagination?.pages || 1;

        renderPosts(data.posts || [], data.topic);

    } catch (e) {
        console.error('Error loading topic:', e);
        document.getElementById('forum-content').innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">❌</div>
                <p>Fehler beim Laden des Themas</p>
                <button class="btn btn-secondary" onclick="loadTopic(${topicId})">Erneut versuchen</button>
            </div>
        `;
    }
}

/**
 * Render posts list
 */
function renderPosts(posts, topic) {
    const container = document.getElementById('forum-content');

    let html = posts.map((post, index) => {
        const isFirst = post.is_first_post;
        const authorInitial = (post.author_display_name || post.author_name || 'U').charAt(0).toUpperCase();
        const canEdit = currentUser && (currentUser.id === post.user_id || currentUser.role === 'admin');

        return `
            <div class="post-card ${isFirst ? 'post-first' : ''}">
                <div class="post-header">
                    <div class="post-author">
                        <div class="post-avatar">${authorInitial}</div>
                        <div>
                            <div class="post-author-name">${escapeHtml(post.author_display_name || post.author_name || 'Unbekannt')}</div>
                            <div class="post-date">${formatDateTime(post.created_at)}</div>
                        </div>
                    </div>
                    <span style="color: var(--color-text-muted); font-size: 0.85rem;">#${(currentPage - 1) * 20 + index + 1}</span>
                </div>
                <div class="post-content">
                    ${formatPostContent(post.content)}
                    ${post.edited_at ? `<div class="post-edited">Bearbeitet am ${formatDateTime(post.edited_at)}${post.editor_name ? ` von ${escapeHtml(post.editor_name)}` : ''}</div>` : ''}
                </div>
                ${canEdit && !isFirst ? `
                    <div class="post-footer">
                        <button class="btn btn-ghost" onclick="editPost(${post.id})" style="font-size: 0.85rem;">Bearbeiten</button>
                        <button class="btn btn-ghost" onclick="deletePost(${post.id})" style="font-size: 0.85rem; color: var(--color-primary);">Löschen</button>
                    </div>
                ` : ''}
            </div>
        `;
    }).join('');

    // Reply form (if topic not locked)
    if (!topic.is_locked) {
        if (currentUser) {
            html += `
                <div class="reply-form" id="reply-form">
                    <h3>Antwort schreiben</h3>
                    <textarea id="reply-content" placeholder="Deine Antwort..."></textarea>
                    <button class="btn btn-primary" onclick="submitReply()">Antworten</button>
                </div>
            `;
        } else {
            html += `
                <div class="login-prompt">
                    <p>Du musst eingeloggt sein, um zu antworten.</p>
                    <a href="admin/index.html" class="btn btn-primary">Jetzt anmelden</a>
                </div>
            `;
        }
    } else {
        html += `
            <div class="login-prompt">
                <p>🔒 Dieses Thema ist geschlossen und kann nicht mehr beantwortet werden.</p>
            </div>
        `;
    }

    container.innerHTML = html;
    renderPagination();
}

// ============================================
// FORMS & ACTIONS
// ============================================

/**
 * Show new topic form
 */
function showNewTopicForm() {
    if (!currentUser) {
        alert('Bitte melde dich an, um ein Thema zu erstellen.');
        return;
    }

    const container = document.getElementById('forum-content');
    const existingForm = document.querySelector('.new-topic-form');

    if (existingForm) {
        existingForm.remove();
        return;
    }

    // If we're in topics view, pre-select the current category
    const categorySelect = currentCategoryId
        ? `<input type="hidden" id="topic-category" value="${currentCategoryId}">`
        : `<div class="form-group">
               <label>Kategorie</label>
               <select id="topic-category" required></select>
           </div>`;

    const formHtml = `
        <div class="new-topic-form">
            <h3 style="margin-bottom: var(--spacing-lg);">Neues Thema erstellen</h3>
            ${categorySelect}
            <div class="form-group">
                <label>Titel</label>
                <input type="text" id="topic-title" placeholder="Thema des Beitrags..." required maxlength="200">
            </div>
            <div class="form-group">
                <label>Inhalt</label>
                <textarea id="topic-content" style="min-height: 200px;" placeholder="Dein Beitrag..." required></textarea>
            </div>
            <div style="display: flex; gap: var(--spacing-md);">
                <button class="btn btn-primary" onclick="submitNewTopic()">Thema erstellen</button>
                <button class="btn btn-secondary" onclick="hideNewTopicForm()">Abbrechen</button>
            </div>
        </div>
    `;

    container.insertAdjacentHTML('afterbegin', formHtml);

    // Load categories if needed
    if (!currentCategoryId) {
        loadCategorySelect();
    }

    // Focus title input
    document.getElementById('topic-title').focus();
}

/**
 * Hide new topic form
 */
function hideNewTopicForm() {
    const form = document.querySelector('.new-topic-form');
    if (form) {
        form.remove();
    }
}

/**
 * Load categories for select dropdown
 */
async function loadCategorySelect() {
    const select = document.getElementById('topic-category');
    if (!select || select.tagName !== 'SELECT') return;

    try {
        const res = await fetch(`${API_BASE}/forum-categories.php`);
        const data = await res.json();

        if (data.categories) {
            select.innerHTML = data.categories.map(cat =>
                `<option value="${cat.id}">${escapeHtml(cat.name)}</option>`
            ).join('');
        }
    } catch (e) {
        console.error('Error loading categories:', e);
    }
}

/**
 * Submit new topic
 */
async function submitNewTopic() {
    const categoryId = document.getElementById('topic-category').value;
    const title = document.getElementById('topic-title').value.trim();
    const content = document.getElementById('topic-content').value.trim();

    if (!categoryId || !title || !content) {
        alert('Bitte alle Felder ausfüllen.');
        return;
    }

    if (title.length < 3) {
        alert('Der Titel muss mindestens 3 Zeichen haben.');
        return;
    }

    if (content.length < 10) {
        alert('Der Inhalt muss mindestens 10 Zeichen haben.');
        return;
    }

    try {
        const res = await fetch(`${API_BASE}/forum-topics.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                category_id: parseInt(categoryId),
                title,
                content,
                csrf_token: csrfToken
            })
        });

        const data = await res.json();

        if (data.success) {
            // Update CSRF token
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
            }
            // Navigate to the new topic
            navigateTo('topic', data.topic_id);
        } else {
            alert(data.error || 'Fehler beim Erstellen des Themas');
        }
    } catch (e) {
        console.error('Error creating topic:', e);
        alert('Netzwerkfehler beim Erstellen des Themas');
    }
}

/**
 * Submit reply to topic
 */
async function submitReply() {
    const content = document.getElementById('reply-content').value.trim();

    if (!content) {
        alert('Bitte einen Text eingeben.');
        return;
    }

    if (content.length < 3) {
        alert('Die Antwort muss mindestens 3 Zeichen haben.');
        return;
    }

    try {
        const res = await fetch(`${API_BASE}/forum-posts.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                action: 'create',
                topic_id: currentTopicId,
                content,
                csrf_token: csrfToken
            })
        });

        const data = await res.json();

        if (data.success) {
            // Update CSRF token
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
            }
            // Reload topic to show new post
            loadTopic(currentTopicId);
        } else {
            alert(data.error || 'Fehler beim Posten der Antwort');
        }
    } catch (e) {
        console.error('Error posting reply:', e);
        alert('Netzwerkfehler beim Posten der Antwort');
    }
}

/**
 * Edit a post
 */
async function editPost(postId) {
    const content = prompt('Bearbeiteter Text:');
    if (content === null) return;

    if (content.trim().length < 3) {
        alert('Der Text muss mindestens 3 Zeichen haben.');
        return;
    }

    try {
        const res = await fetch(`${API_BASE}/forum-posts.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                action: 'edit',
                post_id: postId,
                content: content.trim(),
                csrf_token: csrfToken
            })
        });

        const data = await res.json();

        if (data.success) {
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
            }
            loadTopic(currentTopicId);
        } else {
            alert(data.error || 'Fehler beim Bearbeiten');
        }
    } catch (e) {
        console.error('Error editing post:', e);
        alert('Netzwerkfehler beim Bearbeiten');
    }
}

/**
 * Delete a post
 */
async function deletePost(postId) {
    if (!confirm('Beitrag wirklich löschen?')) return;

    try {
        const res = await fetch(`${API_BASE}/forum-posts.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                action: 'delete',
                post_id: postId,
                csrf_token: csrfToken
            })
        });

        const data = await res.json();

        if (data.success) {
            if (data.csrf_token) {
                csrfToken = data.csrf_token;
            }
            loadTopic(currentTopicId);
        } else {
            alert(data.error || 'Fehler beim Löschen');
        }
    } catch (e) {
        console.error('Error deleting post:', e);
        alert('Netzwerkfehler beim Löschen');
    }
}

// ============================================
// NAVIGATION & UI
// ============================================

/**
 * Navigate to a view
 */
function navigateTo(view, id = null) {
    let url = 'forum.html';

    if (view === 'category' && id) {
        url = `forum.html?category=${id}`;
        currentPage = 1;
    } else if (view === 'topic' && id) {
        url = `forum.html?topic=${id}`;
        currentPage = 1;
    }

    history.pushState({}, '', url);
    parseUrl();
}

/**
 * Update breadcrumb navigation
 */
function updateBreadcrumb(items) {
    const container = document.getElementById('forum-breadcrumb');

    let html = '<a href="forum.html" onclick="event.preventDefault(); navigateTo(\'categories\')">Forum</a>';

    items.forEach((item, index) => {
        html += ' <span>›</span> ';
        if (index === items.length - 1) {
            html += `<span style="color: var(--color-text-primary);">${escapeHtml(item.label)}</span>`;
        } else {
            html += `<a href="${item.url}" onclick="event.preventDefault(); history.pushState({}, '', '${item.url}'); parseUrl();">${escapeHtml(item.label)}</a>`;
        }
    });

    container.innerHTML = html;
}

/**
 * Render pagination controls
 */
function renderPagination() {
    const container = document.getElementById('pagination-container');

    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '<div class="pagination">';

    // Previous button
    html += `<button class="pagination-btn" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>← Zurück</button>`;

    // Page numbers
    const maxVisible = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);
    startPage = Math.max(1, endPage - maxVisible + 1);

    if (startPage > 1) {
        html += `<button class="pagination-btn" onclick="goToPage(1)">1</button>`;
        if (startPage > 2) {
            html += '<span style="padding: 0 var(--spacing-sm);">...</span>';
        }
    }

    for (let i = startPage; i <= endPage; i++) {
        html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += '<span style="padding: 0 var(--spacing-sm);">...</span>';
        }
        html += `<button class="pagination-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`;
    }

    // Next button
    html += `<button class="pagination-btn" onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Weiter →</button>`;

    html += '</div>';
    container.innerHTML = html;
}

/**
 * Go to a specific page
 */
function goToPage(page) {
    if (page < 1 || page > totalPages || page === currentPage) return;

    currentPage = page;

    // Update URL
    const params = new URLSearchParams(window.location.search);
    if (page === 1) {
        params.delete('page');
    } else {
        params.set('page', page);
    }
    const newUrl = 'forum.html' + (params.toString() ? '?' + params.toString() : '');
    history.pushState({}, '', newUrl);

    // Reload current view
    if (currentView === 'topics') {
        loadCategory(currentCategoryId);
    } else if (currentView === 'topic') {
        loadTopic(currentTopicId);
    }

    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ============================================
// HELPERS
// ============================================

/**
 * Toggle mobile menu
 */
function toggleMobileMenu() {
    document.getElementById('mobile-menu').classList.toggle('active');
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Format post content (basic formatting)
 */
function formatPostContent(content) {
    if (!content) return '';

    // Escape HTML first
    let formatted = escapeHtml(content);

    // Convert newlines to paragraphs
    formatted = formatted.split(/\n\n+/).map(p => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('');

    return formatted;
}

/**
 * Format date for display
 */
function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    });
}

/**
 * Format date and time for display
 */
function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Close mobile menu on escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.getElementById('mobile-menu').classList.remove('active');
    }
});
