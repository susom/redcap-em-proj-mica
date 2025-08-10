// components/appRouter/appRouter.jsx
import React from "react";
import { Home } from "../../views/Home/home.jsx";
import { PostSession } from "../../views/PostSession/postsession.jsx";
import { AuthProvider } from "../../Hooks/useAuth.jsx";
import { createHashRouter, RouterProvider, Navigate } from "react-router-dom";

// keep it simple: always go to /home
const router = createHashRouter([
  { path: "/", element: <Navigate to="/home" replace /> },
  { path: "/home", element: <Home /> },
  { path: "/postsession", element: <PostSession /> },
]);

export const AppRouter = () => (
  <AuthProvider>
    <RouterProvider router={router} />
  </AuthProvider>
);
