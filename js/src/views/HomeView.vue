<template>
  <div class="home-view">

    <div class="quick-actions">
      <router-link to="/cleanup" class="action-card cleanup">
        <span class="action-icon">&#128465;</span>
        <span class="action-title">Cleanup</span>
        <span class="action-desc">Bilder swipen und bewerten</span>
      </router-link>
      <router-link to="/photos" class="action-card photos">
        <span class="action-icon">&#128247;</span>
        <span class="action-title">Fotos</span>
        <span class="action-desc">Alle Bilder durchstoebern</span>
      </router-link>
      <router-link to="/collaboration" class="action-card team">
        <span class="action-icon">&#128101;</span>
        <span class="action-title">Team</span>
        <span class="action-desc">Gemeinsam bewerten</span>
      </router-link>
    </div>
  </div>
</template>
<style scoped>
.home-view { max-width: 600px; margin: 0 auto; padding: 10px 20px; }
.hero { text-align: center; padding: 30px 0; }
.hero h2 { margin: 0; font-size: 2em; color: #333; }
.hero p { color: #666; margin: 8px 0 0; }
.quick-actions { display: flex; flex-direction: column; gap: 12px; }
.action-card { display: flex; flex-direction: column; align-items: center; padding: 24px; border-radius: 16px; text-decoration: none; color: #fff; text-align: center; }
.action-card.cleanup { background: linear-gradient(135deg, #667eea, #764ba2); }
.action-card.photos { background: linear-gradient(135deg, #f093fb, #f5576c); }
.action-card.team { background: linear-gradient(135deg, #4facfe, #00f2fe); }
.action-icon { font-size: 36px; margin-bottom: 8px; }
.action-title { font-size: 1.2em; font-weight: 700; }
.action-desc { font-size: 0.85em; opacity: 0.85; margin-top: 4px; }
</style>
