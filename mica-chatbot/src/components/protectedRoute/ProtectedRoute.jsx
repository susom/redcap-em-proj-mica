import { Navigate } from "react-router-dom";
import useAuth from "../../Hooks/useAuth.jsx";

export const ProtectedRoute = ({ children }) => {
    const { authed } = useAuth();
    if (!authed) {
        // user is not authenticated
        console.log('user is not authenticated ... redirecting')
        return <Navigate to="/" replace />;
    }
    return children;
};
