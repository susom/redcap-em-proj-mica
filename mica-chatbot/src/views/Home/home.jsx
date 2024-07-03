import React from "react";
import { Container } from 'react-bootstrap';
import { Messages } from "../../components/messages/messages";
import Header from "../../components/header/header.jsx";
import Footer from "../../components/footer/footer.jsx";
export function Home(){
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
