import React, {useState, useContext} from "react";
import {Alert, Button, Fieldset, TextInput, Card, Center, Container, Grid, Space, Text, Title} from '@mantine/core';
import {Carousel} from '@mantine/carousel';
import {InfoCircle, CCircle, Hash} from "react-bootstrap-icons";
import {useNavigate} from 'react-router-dom';
import useAuth from "../../Hooks/useAuth.jsx";
import { ChatContext } from '../../contexts/Chat';

export function Login() {
    const [error, setError] = useState('')
    const [name, setName] = useState('')
    const [phone, setPhone] = useState('')
    const [email, setEmail] = useState('')
    const [loading, setLoading] = useState(false)
    const [embla, setEmbla] = useState(null);
    const navigate = useNavigate();
    const { replaceSession } = useContext(ChatContext);
    const { login, verifyEmail } = useAuth();

    const handleNext = () => embla?.scrollNext();

    // useEffect(() => {
    //
    // },[])

    const onChange = (e) => {
        const {name, value} = e.target
        if (name === 'first') {
            setName(value);
        } else if (name === 'email') {
            setEmail(value);
        } else if (name === '2FA') {
            setPhone(value)
        }
    }

    const onLogin = () => {
        // handleNext()
        setLoading(true)
        login(name, email).then((res) => {
            setLoading(false)
            setError('')

            // user has been cached within 30 minutes, allow entry without verification step
            if(res === 'pass'){
                navigate('/home')
            } else {
                handleNext()
            }
        }).catch((err) => {
            setLoading(false)
            setError(err)
            console.log('user has been rejected ', err)
        })

    }

    const onVerify = () => {
        setLoading(true)
        verifyEmail(phone).then(res => {
            if (res.current_session && res.current_session.length > 0) {
                const sessionData = {
                    session_id: Date.now().toString(), // Or use an ID from res if available
                    queries: res.current_session, // Ensure this matches the expected format
                };
                replaceSession(sessionData);
            }
            setLoading(false);
            navigate('/home')
            console.log("success!")
        }).catch(err => {
            setLoading(false)
            setError(err)
            console.error('reject verify')
        })
        // navigate('/home')
    }

    const disclaimer = () => {
        return (
            <div>
                <Text fw={500} c="dimmed">Terms of Usage</Text>
                <Space h="sm"/>
                <Text size="sm"> Before we get started, it's important to let you know that this is a chatbot session. While our chatbot is designed to provide helpful and supportive responses, please remember that there is no human monitoring this data in real-time. If you are experiencing any acute issues or need immediate assistance, we strongly encourage you to reach out to your nearest health center or emergency services.
                    Your experience and opinions are incredibly valuable to us. By participating in this session, you're helping to shape the future of alcohol counseling and improve support systems for others. Your feedback will contribute to enhancing the effectiveness of these tools, making them even more responsive to the needs of individuals seeking help.
                    Thank you for your time and contribution. Let's get started!
                </Text>
                <div>
                    <Center>
                        <Button
                            test="1"
                            mt="md"
                            radius="md"
                            onClick={handleNext}
                            variant="filled"
                            color="rgba( 140, 21, 21)"
                            style={{width: '120px'}}
                        >
                            Continue
                        </Button>
                    </Center>
                </div>
            </div>
        )
    }

    const loginView = () => {
        return (
            <div>
                {error &&
                    <Alert variant="light" color="red" radius="md" title="Error">{error}</Alert>
                }
                <Space h="sm"/>
                <Fieldset legend="Registration information">
                    <TextInput name="first" onChange={onChange} label="First name" placeholder="Your name"/>
                    <TextInput name="email" onChange={onChange} label="Email" placeholder="Email" mt="md"/>
                    <Center>
                        <Button
                            test="1"
                            mt="md"
                            radius="md"
                            onClick={onLogin}
                            variant="filled"
                            color="rgba( 140, 21, 21)"
                            style={{width: '120px'}}
                            loading={loading}
                        >
                            Login
                        </Button>
                    </Center>
                </Fieldset>
                <Space h="sm"/>
            </div>
        )
    }

    const twoFactorInput = () => {
        return (
            <div>
                {error &&
                    <Alert variant="light" color="red" radius="md" title="Error">{error}</Alert>
                }
                <Space h="sm"/>
                <Fieldset legend="Please enter the 6-digit code sent via email" style={{height: '242px'}}>
                    <Space h="lg"/>
                    <Space h="lg"/>
                    <TextInput
                        leftSection={<Hash/>}
                        label="Code"
                        placeholder="__ __ __ __ __ __ "
                        onChange={onChange}
                        name="2FA"
                    />
                    <Space h="lg"/>
                    <Space h="lg"/>
                    <Center>
                        <Button
                            loading={loading}
                            mt="md"
                            radius="md"
                            onClick={onVerify}
                            variant="filled"
                            color="rgba( 140, 21, 21)"
                            style={{width: '120px'}}
                        >
                            Validate
                        </Button>
                    </Center>
                </Fieldset>
                <Space h="sm"/>
            </div>
        )
    }

    const renderCarousel = () => {
        return (
            <Carousel
                loop
                withControls={false}
                draggable={false}
                withKeyboardEvents={false}
                getEmblaApi={setEmbla}
            >
                <Carousel.Slide>{disclaimer()}</Carousel.Slide>
                <Carousel.Slide>{loginView()}</Carousel.Slide>
                <Carousel.Slide>{twoFactorInput()}</Carousel.Slide>
            </Carousel>
        )
    }

    return (
        <div style={{justifyContent: 'center'}} className="content">
            <Container>
                <Card shadow="lg" padding="lg" radius="md" withBorder style={{minWidth: '800px'}}>
                    <Title order={3}>Welcome to MICA!</Title>
                    <Space h="sm"/>
                    <Grid>
                        <Grid.Col span={7}>
                            {renderCarousel()}
                        </Grid.Col>
                        <Grid.Col span={5}>
                            <Center>
                                <div className="Splash"></div>
                            </Center>
                        </Grid.Col>
                    </Grid>
                    <Center>
                        <Text size="xs" c="dimmed">
                                    <span style={{verticalAlign: 'middle'}}>
                                        <CCircle/> Stanford Medicine
                                    </span>
                        </Text>
                    </Center>
                </Card>
            </Container>
        </div>
    );
}
