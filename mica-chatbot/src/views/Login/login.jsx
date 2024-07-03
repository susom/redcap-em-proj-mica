import React, {useState} from "react";
import {Button, Fieldset, TextInput, Card, Center, Container, Grid, Space, Text, Title, Transition} from '@mantine/core';
import {CCircle} from "react-bootstrap-icons";
export function Login({changeView}){
    const [viewLogin, setViewLogin] = useState(false)
    const onClick = () => {
        setViewLogin(!viewLogin)
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
                    Sed congue, velit sit amet rutrum ultrices, augue ligula pretium eros, et sagittis
                    nisl nibh in leo. Praesent dui justo, luctus
                    a turpis non, euismod tristique nisl</Text>
                <div>
                    <Center>
                        <Button mt="md" radius="md" onClick={onClick} variant="filled"
                                color="rgba( 140, 21, 21)" style={{width: '120px'}}>
                            Accept
                        </Button>
                    </Center>
                </div>
            </div>
        )
    }

    const login = () => {
        return (
                <div>
                    <Space h="sm"/>
                    <Fieldset legend="Personal information">
                        <TextInput label="Your name" placeholder="Your name"/>
                        <TextInput label="Email" placeholder="Email" mt="md"/>
                        <Center>
                            <Button mt="md" radius="md" onClick={onClick} variant="filled"
                                    color="rgba( 140, 21, 21)" style={{width: '120px'}}>
                                Login
                            </Button>
                        </Center>
                    </Fieldset>
                    <Space h="sm"/>
                </div>
        )
    }

    const transitionWrapper = () => {
        return (
            <Transition transition="fade" mounted={viewLogin}>
                {(styles) => <div style={login()}> </div>}
            </Transition>
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
                            {viewLogin ? login() : disclaimer()}
                        </Grid.Col>
                        <Grid.Col span={5}>
                            <Center>
                                <div className="Splash"></div>
                            </Center>
                        </Grid.Col>
                    </Grid>
                        <Center>
                            <Text size="xs" c="dimmed">
                                    <span style={{verticalAlign:'middle'}}>
                                        <CCircle/> Stanford Medicine
                                    </span>
                            </Text>
                        </Center>
                    </Card>
                </Container>
        </div>
    );
}
