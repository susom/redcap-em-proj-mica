import React, {useEffect, useContext} from "react";
import { Container } from 'react-bootstrap';
import { Messages } from "../../components/messages/messages";
import { ChatContext } from "../../contexts/Chat";
import Header from "../../components/header/header.jsx";
import Footer from "../../components/footer/footer.jsx";


export function Home(){
    const { fetchSavedSession } = useContext(ChatContext);

    useEffect(() => {
        fetchSavedSession();
    }, []);

    return (
        <>
            <Header />
                <div className="content">
                    <Container className={`body`}>
                        <Messages/>
                    </Container>
                </div>
            <Footer />
        </>
    );
}
