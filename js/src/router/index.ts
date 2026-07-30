import { createRouter, createWebHashHistory } from "vue-router"

const routes = [
  {
    path: "/",
    name: "Home",
    component: () => import("@/views/HomeView.vue")
  },
  {
    path: "/photos",
    name: "Photos",
    component: () => import("@/views/PhotosView.vue")
  },
  {
    path: "/cleanup",
    name: "Cleanup",
    component: () => import("@/views/CleanupView.vue")
  },
  {
    path: "/cleanup-execute",
    name: "CleanupExecute",
    component: () => import("@/views/CleanupExecuteView.vue")
  },
  {
    path: "/collaboration",
    name: "Collaboration",
    component: () => import("@/views/CollaborationView.vue")
  },
  {
    path: "/settings",
    name: "Settings",
    component: () => import("@/views/SettingsView.vue")
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes
})

export default router
