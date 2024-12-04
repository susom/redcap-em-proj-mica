import React from 'react';
import { useLocation } from 'react-router-dom';

export function PostSession() {
    const location = useLocation();
    const surveyLink = location.state?.surveyLink;

    return (
        <div id="chatbot_ui_container">
            <div className="full-screen-container">
                <div className="content">
                    <div className="body">
                        <div className="messages">
                            <h2 style={{ color: '#ffffff', textAlign: 'center', marginBottom: '20px' }}>
                                Post-Session Survey
                            </h2>
                            {surveyLink ? (
                                <p style={{ textAlign: 'center', color: '#ffffff' }}>
                                    Thank you for completing the session! Please click the link below to complete a short survey:
                                    <br />
                                    <a
                                        href={surveyLink}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={{
                                            color: '#007BFF',
                                            textDecoration: 'underline',
                                            fontWeight: 'bold',
                                        }}
                                    >
                                        Complete Survey
                                    </a>
                                </p>
                            ) : (
                                <p style={{ textAlign: 'center', color: '#ffffff' }}>
                                    No survey link available. Please contact support.
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
