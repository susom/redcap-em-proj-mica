import React from "react";
import App from "../../App.jsx";
import {Login} from "../../views/Login/login.jsx";
import {Home} from '../../views/Home/home.jsx';
import {History} from '../../views/History/history.jsx';
import {Splash} from '../../views/Splash/splash.jsx';
import {ProtectedRoute} from "../protectedRoute/ProtectedRoute.jsx";
import {AuthProvider} from "../../Hooks/useAuth.jsx";

import {
    createHashRouter,
    RouterProvider,
    createRoutesFromElements
} from "react-router-dom";

console.log('inside router...')
const router = createHashRouter([
    {
        path: '/',
        element: <Login />
    },
    {
        path: '/splash',
        element: <Splash/>
    },
    {
        path: '/home',
        element: <ProtectedRoute><Home/></ProtectedRoute>
    }
])


export const AppRouter = () => <AuthProvider><RouterProvider router={router}/></AuthProvider>
