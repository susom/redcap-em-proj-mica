import React, {useState, useEffect} from "react";
import {Box, Button, Fieldset, TextInput, Card, Center, Container, Grid, Space, Text, Title} from '@mantine/core';
import {Carousel} from '@mantine/carousel';
import {CCircle, Hash} from "react-bootstrap-icons";
import {useNavigate} from 'react-router-dom';
import {user_info} from "../../components/database/dexie.js";
import useAuth from "../../Hooks/useAuth.jsx";

export function Login({changeView}) {
    const [viewLogin, setViewLogin] = useState(false)
    const [name, setName] = useState('')
    const [email, setEmail] = useState('')
    const [embla, setEmbla] = useState(null);
    const handleNext = () => embla?.scrollNext();
    const navigate = useNavigate();
    const { login } = useAuth();

    // useEffect(() => {
    //
    // },[])

    const onChange = (e) => {
        const {name, value} = e.target
        if (name === 'first') {
            setName(value);
        } else if (name === 'email') {
            setEmail(value);
        }
    }

    const onLogin = () => {
        login(name, email).then((res) => {
            handleNext()
        }).catch((err) => {
            console.log('user has been rejected ', err)
        })

    }

    const onVerify = () => {
        console.log('verify')
        navigate('/home')
    }

    const disclaimer = () => {
        return (
            <div>
                <Text fw={500} c="dimmed">Terms of Usage</Text>
                <Title order={2}>Welcome</Title>
                <Space h="sm"/>
                <Text size="sm">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Mauris in dui
                    elit. Aenean a nisl ultrices, convallis ipsum quis,
                    ornare enim. Sed ac scelerisque turpis, et pellentesque nibh. Donec ligula augue,
                    rutrum non diam ut, euismod fermentum turpis.
                    Donec nunc ante, facilisis faucibus pellentesque sed, dictum vel nunc. Sed nec enim
                    nibh. Proin auctor orci a gravida pulvinar.
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
                            Login
                        </Button>
                    </Center>
                </div>
            </div>
        )
    }

    const loginView = () => {
        return (
            <div>
                <Space h="sm"/>
                <Fieldset legend="Personal information">
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
                <Space h="sm"/>
                <Fieldset legend="Please enter the 6-digit code sent via text message" style={{height: '242px'}}>
                    <Space h="lg"/>
                    <Space h="lg"/>
                    <TextInput
                        leftSection={<Hash/>}
                        label="Code"
                        placeholder="__ __ __ __ __ __ "
                    />
                    <Space h="lg"/>
                    <Space h="lg"/>
                    <Center>
                        <Button name="2FA" mt="md" radius="md" onClick={onVerify} variant="filled"
                                color="rgba( 140, 21, 21)" style={{width: '120px'}}>
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
                    <Title order={3}>Welcome to MICA</Title>
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
