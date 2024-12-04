import React from "react";
import { Login } from "../../views/Login/login.jsx";
import { Home } from '../../views/Home/home.jsx';
import { Splash } from '../../views/Splash/splash.jsx';
import { PostSession } from '../../views/PostSession/postsession.jsx';
import { ProtectedRoute } from "../protectedRoute/ProtectedRoute.jsx";
import { AuthProvider } from "../../Hooks/useAuth.jsx";

import {
    createHashRouter,
    RouterProvider,
} from "react-router-dom";

const router = createHashRouter([
    {
        path: '/',
        element: <Login />
    },
    {
        path: '/splash',
        element: <Splash />
    },
    {
        path: '/home',
        element: (
            <ProtectedRoute>
                <Home />
            </ProtectedRoute>
        )
    },
    {
        path: '/postsession',
        element: (
            <ProtectedRoute>
                <PostSession />
            </ProtectedRoute>
        )
    }
]);

export const AppRouter = () => (
    <AuthProvider>
        <RouterProvider router={router} />
    </AuthProvider>
);
