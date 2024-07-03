import React from "react";
import App from "../../App.jsx";
import {Login} from "../../views/Login/login.jsx";
import {Home} from '../../views/Home/home.jsx';
import {History} from '../../views/History/history.jsx';
import {Splash} from '../../views/Splash/splash.jsx';

import {
    createBrowserRouter,
    RouterProvider
} from "react-router-dom";


const router = createBrowserRouter([
    {
        path: '/',
        element: <App />
    },
    {
        path: '/login',
        element: <Login/>
    },
    {
        path: '/splash',
        element: <Splash/>
    },
    {
        path: '/home',
        element: <Home/>
    },
    // {
    //     path: '/history',
    //     element: <History/>
    // }
])


export const AppRouter = () => <RouterProvider router={router}/>
